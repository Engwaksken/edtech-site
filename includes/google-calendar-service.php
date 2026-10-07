<?php
declare(strict_types=1);

require_once __DIR__ . '/google-calendar-config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/encryption.php';

if (!class_exists('Google_Client')) {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($autoload)) {
        require_once $autoload;
    }
}

class GoogleCalendarService
{
    private mysqli $conn;


    private array $reconnectNeeded = [];

    public function __construct(mysqli $conn)
    {
        $this->conn = $conn;
    }

  
    public function needsReconnect(int $mentorId): bool
    {
        return !empty($this->reconnectNeeded[$mentorId]);
    }

    private function baseClient(): Google_Client
    {
        $client = new Google_Client();
        $client->setClientId(GOOGLE_CALENDAR_CLIENT_ID);
        $client->setClientSecret(GOOGLE_CALENDAR_CLIENT_SECRET);
        $client->setRedirectUri(GOOGLE_CALENDAR_REDIRECT_URI);
        $client->setScopes([
           
            Google_Service_Calendar::CALENDAR,
            'https://www.googleapis.com/auth/userinfo.email',
        ]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        return $client;
    }

    public function getAuthUrl(int $mentorId): string
    {
        $client = $this->baseClient();
        $client->setState(site_calendar_oauth_state($mentorId));
        return $client->createAuthUrl();
    }

    public function handleCallback(string $code): ?int
    {
        $state = $_GET['state'] ?? '';
        $mentorId = is_string($state) ? site_consume_calendar_oauth_state($state) : null;
        if ($mentorId === null) {
            return null;
        }

        $client = $this->baseClient();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (empty($token['access_token'])) {
            error_log('GoogleCalendarService::handleCallback: token exchange failed.');
            return null;
        }

        $client->setAccessToken($token);

        $googleEmail = '';
        try {
            $oauth2 = new Google_Service_Oauth2($client);
            $googleEmail = (string)($oauth2->userinfo->get()->getEmail() ?? '');
        } catch (\Throwable $e) {
            error_log('GoogleCalendarService::handleCallback userinfo lookup failed: ' . $e->getMessage());
        }

        $refreshToken = $token['refresh_token'] ?? null;
        if ($refreshToken === null) {
            $stmt = $this->conn->prepare('SELECT refresh_token FROM mentor_google_tokens WHERE mentor_id = ? LIMIT 1');
            $stmt->bind_param('i', $mentorId);
            $stmt->execute();
            $existing = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $refreshToken = !empty($existing['refresh_token'])
                ? site_decrypt_secret((string)$existing['refresh_token'], 'calendar:refresh:' . $mentorId) : null;
        }

        $accessToken = (string)$token['access_token'];
        $expiresAt   = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3600));
        $scope       = (string)($token['scope'] ?? '');

    
        $calendarId = $this->createDedicatedCalendar($client, $googleEmail);
        if ($calendarId === null) {
            error_log("GoogleCalendarService::handleCallback: could not create dedicated calendar for mentor {$mentorId}; connection aborted so we don't fall back to the personal calendar.");
            return null;
        }

        $stmt = $this->conn->prepare('
            INSERT INTO mentor_google_tokens
                (mentor_id, access_token, refresh_token, token_expires_at, scope, calendar_id, google_email)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                access_token     = VALUES(access_token),
                refresh_token    = COALESCE(VALUES(refresh_token), refresh_token),
                token_expires_at = VALUES(token_expires_at),
                scope            = VALUES(scope),
                google_email     = VALUES(google_email)
        ');
        $accessToken = site_encrypt_secret($accessToken, 'calendar:access:' . $mentorId);
        $refreshToken = $refreshToken !== null ? site_encrypt_secret((string)$refreshToken, 'calendar:refresh:' . $mentorId) : null;
        $stmt->bind_param('issssss', $mentorId, $accessToken, $refreshToken, $expiresAt, $scope, $calendarId, $googleEmail);
        $stmt->execute();
        $stmt->close();

        return $mentorId;
    }

    public function isConnected(int $mentorId): bool
    {
        $stmt = $this->conn->prepare('SELECT id FROM mentor_google_tokens WHERE mentor_id = ? LIMIT 1');
        $stmt->bind_param('i', $mentorId);
        $stmt->execute();
        $has = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $has;
    }

    public function connectedEmail(int $mentorId): string
    {
        $stmt = $this->conn->prepare('SELECT google_email FROM mentor_google_tokens WHERE mentor_id = ? LIMIT 1');
        $stmt->bind_param('i', $mentorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (string)($row['google_email'] ?? '');
    }

    public function disconnect(int $mentorId): void
    {
        $stmt = $this->conn->prepare('DELETE FROM mentor_google_tokens WHERE mentor_id = ?');
        $stmt->bind_param('i', $mentorId);
        $stmt->execute();
        $stmt->close();
    }


    private function contextFor(int $mentorId): ?array
    {
        $stmt = $this->conn->prepare('SELECT * FROM mentor_google_tokens WHERE mentor_id = ? LIMIT 1');
        $stmt->bind_param('i', $mentorId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
          
            return null;
        }

        $row['access_token'] = site_decrypt_secret((string)$row['access_token'], 'calendar:access:' . $mentorId);
        $row['refresh_token'] = site_decrypt_secret((string)($row['refresh_token'] ?? ''), 'calendar:refresh:' . $mentorId);
        $client = $this->baseClient();
        $client->setAccessToken([
            'access_token'  => $row['access_token'],
            'refresh_token' => $row['refresh_token'],
            'expires_in'    => max(0, strtotime((string)$row['token_expires_at']) - time()),
        ]);

        if ($client->isAccessTokenExpired()) {
            if (empty($row['refresh_token'])) {
                error_log("GoogleCalendarService: mentor {$mentorId} has no refresh_token; must reconnect Google Calendar.");
                $this->reconnectNeeded[$mentorId] = true;
                return null;
            }

            $new = $client->fetchAccessTokenWithRefreshToken((string)$row['refresh_token']);
            if (empty($new['access_token'])) {
                error_log("GoogleCalendarService: token refresh failed for mentor {$mentorId}.");
                $this->reconnectNeeded[$mentorId] = true;
                return null;
            }

            $accessToken = (string)$new['access_token'];
            $expiresAt   = date('Y-m-d H:i:s', time() + (int)($new['expires_in'] ?? 3600));

            $upd = $this->conn->prepare('UPDATE mentor_google_tokens SET access_token = ?, token_expires_at = ? WHERE mentor_id = ?');
            $encryptedAccess = site_encrypt_secret($accessToken, 'calendar:access:' . $mentorId);
            $upd->bind_param('ssi', $encryptedAccess, $expiresAt, $mentorId);
            $upd->execute();
            $upd->close();

            $client->setAccessToken($new + ['refresh_token' => $row['refresh_token']]);
        }

        $calendarId = (string)($row['calendar_id'] ?? '');

 
        if ($calendarId === '' || $calendarId === 'primary') {
            $created = $this->createDedicatedCalendar($client, (string)($row['google_email'] ?? ''));

            if ($created === null) {
           
                error_log("GoogleCalendarService: mentor {$mentorId} needs to reconnect Google Calendar to grant calendar-management access.");
                $this->reconnectNeeded[$mentorId] = true;
                return null;
            }

            $calendarId = $created;

            $upd = $this->conn->prepare('UPDATE mentor_google_tokens SET calendar_id = ? WHERE mentor_id = ?');
            $upd->bind_param('si', $calendarId, $mentorId);
            $upd->execute();
            $upd->close();
        }

        return ['client' => $client, 'calendarId' => $calendarId];
    }

   
    private function createDedicatedCalendar(Google_Client $client, string $ownerEmailForLog): ?string
    {
        try {
            $service = new Google_Service_Calendar($client);

            $calendar = new Google_Service_Calendar_Calendar([
                'summary'     => GOOGLE_CALENDAR_DEDICATED_NAME,
                'description' => 'Created automatically to keep mentoring availability and sessions separate from your personal calendar.',
                'timeZone'    => date_default_timezone_get() ?: 'UTC',
            ]);

            $created = $service->calendars->insert($calendar);
            return (string)$created->getId();
        } catch (\Throwable $e) {
            error_log('GoogleCalendarService::createDedicatedCalendar failed for ' . $ownerEmailForLog . ': ' . $e->getMessage());
            return null;
        }
    }

    public function upsertAvailabilityEvent(
        int $mentorId,
        ?string $existingEventId,
        string $date,          // Y-m-d
        string $slotTime,      // H:i
        int $durationMinutes = 60,
        string $meetingLink = ''
    ): ?string {
        $ctx = $this->contextFor($mentorId);
        if (!$ctx) {
            return null;
        }

        $service    = new Google_Service_Calendar($ctx['client']);
        $calendarId = $ctx['calendarId'];

        $start = new DateTime("{$date} {$slotTime}");
        $end   = (clone $start)->modify("+{$durationMinutes} minutes");

        $event = new Google_Service_Calendar_Event([
            'summary'      => 'Open for mentoring session',
            'description'  => 'This slot is marked as available for a venture to book a mentoring session.'
                . ($meetingLink !== '' ? "\n\nMeeting link: {$meetingLink}" : ''),
            'start'        => ['dateTime' => $start->format(DateTime::RFC3339)],
            'end'          => ['dateTime' => $end->format(DateTime::RFC3339)],
            'transparency' => 'transparent', // doesn't show the mentor as "busy"
            'colorId'      => '2',
        ]);

        try {
            if ($existingEventId) {
                return $service->events->update($calendarId, $existingEventId, $event)->getId();
            }
            return $service->events->insert($calendarId, $event)->getId();
        } catch (\Throwable $e) {
            error_log("GoogleCalendarService::upsertAvailabilityEvent failed for mentor {$mentorId}: " . $e->getMessage());
            return $existingEventId;
        }
    }

    /** Delete any calendar event (availability block or session) by id. Safe to call on an already-deleted event. */
    public function deleteEvent(int $mentorId, ?string $eventId): void
    {
        if (!$eventId) {
            return;
        }

        $ctx = $this->contextFor($mentorId);
        if (!$ctx) {
            return;
        }

        $service    = new Google_Service_Calendar($ctx['client']);
        $calendarId = $ctx['calendarId'];

        try {
            $service->events->delete($calendarId, $eventId, ['sendUpdates' => 'all']);
        } catch (\Throwable $e) {
            // 404/410 just means it's already gone - fine either way.
            error_log("GoogleCalendarService::deleteEvent failed for mentor {$mentorId}, event {$eventId}: " . $e->getMessage());
        }
    }

    public function upsertSessionEvent(
        int $organizerMentorId,
        ?string $existingEventId,
        string $title,
        ?string $scheduledAt,   // Y-m-d H:i:s, null if not yet scheduled
        int $durationMinutes,
        string $description,
        string $meetingLink,
        array $attendeeEmails
    ): ?string {
        if (!$scheduledAt) {
            return $existingEventId; // nothing to put on the calendar yet
        }

        $ctx = $this->contextFor($organizerMentorId);
        if (!$ctx) {
            return null;
        }

        $service    = new Google_Service_Calendar($ctx['client']);
        $calendarId = $ctx['calendarId'];

        $start = new DateTime($scheduledAt);
        $end   = (clone $start)->modify('+' . max(15, $durationMinutes) . ' minutes');

        $attendees = [];
        foreach ($attendeeEmails as $a) {
            $email = trim((string)($a['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $attendees[] = new Google_Service_Calendar_EventAttendee([
                'email'       => $email,
                'displayName' => (string)($a['name'] ?? ''),
            ]);
        }

        $event = new Google_Service_Calendar_Event([
            'summary'     => $title !== '' ? $title : 'Mentoring session',
            'description' => $description !== '' ? $description : null,
            'location'    => $meetingLink !== '' ? $meetingLink : null,
            'start'       => ['dateTime' => $start->format(DateTime::RFC3339)],
            'end'         => ['dateTime' => $end->format(DateTime::RFC3339)],
            'attendees'   => $attendees,
            'reminders'   => ['useDefault' => true],
        ]);

        $params = [
            'sendUpdates' => 'all',
        ];

        try {
            if ($existingEventId) {
                return $service->events->update($calendarId, $existingEventId, $event, $params)->getId();
            }
            return $service->events->insert($calendarId, $event, $params)->getId();
        } catch (\Throwable $e) {
            error_log("GoogleCalendarService::upsertSessionEvent failed for mentor {$organizerMentorId}: " . $e->getMessage());
            return $existingEventId;
        }
    }

    public function cancelSessionEvent(int $organizerMentorId, ?string $eventId): void
    {
        $this->deleteEvent($organizerMentorId, $eventId);
    }
}
