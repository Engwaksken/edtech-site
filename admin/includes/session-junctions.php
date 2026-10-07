<?php
declare(strict_types=1);

if (!function_exists('ms_tbl_exists')) {
    function ms_tbl_exists(mysqli $conn, string $table): bool
    {
        $table = $conn->real_escape_string($table);
        $res = $conn->query("SHOW TABLES LIKE '{$table}'");
        return $res instanceof mysqli_result && $res->num_rows > 0;
    }
}

if (!function_exists('ms_col_exists')) {
    function ms_col_exists(mysqli $conn, string $table, string $column): bool
    {
        $table  = $conn->real_escape_string($table);
        $column = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `{$table}` LIKE '{$column}'");
        return $res instanceof mysqli_result && $res->num_rows > 0;
    }
}

if (!function_exists('ms_get_session_mentors')) {
    function ms_get_session_mentors(mysqli $conn, int $session_id): array
    {
        $out = [];

        $st = $conn->prepare("
            SELECT sm.mentor_id,
                   COALESCE(sm.role, 'organizer') AS role,
                   COALESCE(sm.invite_status, 'accepted') AS invite_status,
                   sm.responded_at,
                   m.full_name,
                   m.email,
                   m.photo
            FROM session_mentors sm
            INNER JOIN mentors m ON m.id = sm.mentor_id
            WHERE sm.session_id = ?
            ORDER BY (sm.role = 'organizer') DESC, m.full_name ASC
        ");

        if ($st) {
            $st->bind_param('i', $session_id);
            $st->execute();
            $res = $st->get_result();

            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }

            $st->close();
        }

        if ($out) {
            return $out;
        }

        $st = $conn->prepare("
            SELECT ms.mentor_id,
                   'organizer' AS role,
                   'accepted' AS invite_status,
                   ms.confirmed_at AS responded_at,
                   m.full_name,
                   m.email,
                   m.photo
            FROM mentor_sessions ms
            INNER JOIN mentors m ON m.id = ms.mentor_id
            WHERE ms.id = ?
              AND ms.mentor_id IS NOT NULL
              AND ms.mentor_id > 0
            LIMIT 1
        ");

        if ($st) {
            $st->bind_param('i', $session_id);
            $st->execute();
            $res = $st->get_result();

            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }

            $st->close();
        }

        return $out;
    }
}

if (!function_exists('ms_get_session_ventures')) {
    function ms_get_session_ventures(mysqli $conn, int $session_id): array
    {
        $out = [];

        $st = $conn->prepare("
            SELECT sv.venture_id,
                   v.name,
                   v.email AS founder_email
            FROM session_ventures sv
            INNER JOIN ventures v ON v.id = sv.venture_id
            WHERE sv.session_id = ?
            ORDER BY v.name ASC
        ");

        if ($st) {
            $st->bind_param('i', $session_id);
            $st->execute();
            $res = $st->get_result();

            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }

            $st->close();
        }

        if ($out) {
            return $out;
        }

        $st = $conn->prepare("
            SELECT ms.venture_id,
                   v.name,
                   v.email AS founder_email
            FROM mentor_sessions ms
            INNER JOIN ventures v ON v.id = ms.venture_id
            WHERE ms.id = ?
              AND ms.venture_id IS NOT NULL
              AND ms.venture_id > 0
            LIMIT 1
        ");

        if ($st) {
            $st->bind_param('i', $session_id);
            $st->execute();
            $res = $st->get_result();

            while ($r = $res->fetch_assoc()) {
                $out[] = $r;
            }

            $st->close();
        }

        return $out;
    }
}

if (!function_exists('ms_get_mentors_for_sessions')) {
    function ms_get_mentors_for_sessions(mysqli $conn, array $session_ids): array
    {
        $out = [];
        $session_ids = array_values(array_unique(array_filter(array_map('intval', $session_ids))));

        if (!$session_ids) {
            return $out;
        }

        $in = implode(',', $session_ids);

        $res = $conn->query("
            SELECT sm.session_id,
                   sm.mentor_id,
                   COALESCE(sm.role, 'organizer') AS role,
                   COALESCE(sm.invite_status, 'accepted') AS invite_status,
                   sm.responded_at,
                   m.full_name,
                   m.email,
                   m.photo
            FROM session_mentors sm
            INNER JOIN mentors m ON m.id = sm.mentor_id
            WHERE sm.session_id IN ($in)
            ORDER BY sm.session_id ASC, (sm.role = 'organizer') DESC, m.full_name ASC
        ");

        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $out[(int)$r['session_id']][] = $r;
            }
        } else {
            error_log('ms_get_mentors_for_sessions failed: ' . $conn->error);
        }

        $missing = [];
        foreach ($session_ids as $sid) {
            if (empty($out[$sid])) {
                $missing[] = $sid;
            }
        }

        if ($missing) {
            $missing_in = implode(',', $missing);

            $res = $conn->query("
                SELECT ms.id AS session_id,
                       ms.mentor_id,
                       'organizer' AS role,
                       'accepted' AS invite_status,
                       ms.confirmed_at AS responded_at,
                       m.full_name,
                       m.email,
                       m.photo
                FROM mentor_sessions ms
                INNER JOIN mentors m ON m.id = ms.mentor_id
                WHERE ms.id IN ($missing_in)
                  AND ms.mentor_id IS NOT NULL
                  AND ms.mentor_id > 0
                ORDER BY ms.id ASC, m.full_name ASC
            ");

            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $out[(int)$r['session_id']][] = $r;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('ms_get_ventures_for_sessions')) {
    function ms_get_ventures_for_sessions(mysqli $conn, array $session_ids): array
    {
        $out = [];
        $session_ids = array_values(array_unique(array_filter(array_map('intval', $session_ids))));

        if (!$session_ids) {
            return $out;
        }

        $in = implode(',', $session_ids);

        $res = $conn->query("
            SELECT sv.session_id,
                   sv.venture_id,
                   v.name,
                   v.email AS founder_email
            FROM session_ventures sv
            INNER JOIN ventures v ON v.id = sv.venture_id
            WHERE sv.session_id IN ($in)
            ORDER BY sv.session_id ASC, v.name ASC
        ");

        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $out[(int)$r['session_id']][] = $r;
            }
        } else {
            error_log('ms_get_ventures_for_sessions failed: ' . $conn->error);
        }

        $missing = [];
        foreach ($session_ids as $sid) {
            if (empty($out[$sid])) {
                $missing[] = $sid;
            }
        }

        if ($missing) {
            $missing_in = implode(',', $missing);

            $res = $conn->query("
                SELECT ms.id AS session_id,
                       ms.venture_id,
                       v.name,
                       v.email AS founder_email
                FROM mentor_sessions ms
                INNER JOIN ventures v ON v.id = ms.venture_id
                WHERE ms.id IN ($missing_in)
                  AND ms.venture_id IS NOT NULL
                  AND ms.venture_id > 0
                ORDER BY ms.id ASC, v.name ASC
            ");

            if ($res instanceof mysqli_result) {
                while ($r = $res->fetch_assoc()) {
                    $out[(int)$r['session_id']][] = $r;
                }
            }
        }

        return $out;
    }
}

if (!function_exists('ms_upsert_session_mentor')) {
    function ms_upsert_session_mentor(
        mysqli $conn,
        int $session_id,
        int $mentor_id,
        string $role,
        string $invite_status,
        bool $set_responded
    ): bool {
        $id = 0;

        $st = $conn->prepare("
            SELECT id
            FROM session_mentors
            WHERE session_id = ?
              AND mentor_id = ?
            LIMIT 1
        ");

        if (!$st) {
            error_log('ms_upsert_session_mentor select prepare failed: ' . $conn->error);
            return false;
        }

        $st->bind_param('ii', $session_id, $mentor_id);
        $st->execute();
        $res = $st->get_result();

        if ($row = $res->fetch_assoc()) {
            $id = (int)$row['id'];
        }

        $st->close();

        if ($id > 0) {
            if ($set_responded) {
                $st = $conn->prepare("
                    UPDATE session_mentors
                    SET role = ?,
                        invite_status = ?,
                        responded_at = COALESCE(responded_at, NOW())
                    WHERE id = ?
                ");
            } else {
                $st = $conn->prepare("
                    UPDATE session_mentors
                    SET role = ?
                    WHERE id = ?
                ");
            }

            if (!$st) {
                error_log('ms_upsert_session_mentor update prepare failed: ' . $conn->error);
                return false;
            }

            if ($set_responded) {
                $st->bind_param('ssi', $role, $invite_status, $id);
            } else {
                $st->bind_param('si', $role, $id);
            }

            $ok = $st->execute();
            if (!$ok) {
                error_log('ms_upsert_session_mentor update failed: ' . $st->error);
            }

            $st->close();
            return $ok;
        }

        if ($set_responded) {
            $st = $conn->prepare("
                INSERT INTO session_mentors
                    (session_id, mentor_id, role, invite_status, responded_at, created_at)
                VALUES
                    (?, ?, ?, ?, NOW(), NOW())
            ");
        } else {
            $st = $conn->prepare("
                INSERT INTO session_mentors
                    (session_id, mentor_id, role, invite_status, created_at)
                VALUES
                    (?, ?, ?, ?, NOW())
            ");
        }

        if (!$st) {
            error_log('ms_upsert_session_mentor insert prepare failed: ' . $conn->error);
            return false;
        }

        $st->bind_param('iiss', $session_id, $mentor_id, $role, $invite_status);

        $ok = $st->execute();
        if (!$ok) {
            error_log('ms_upsert_session_mentor insert failed: ' . $st->error);
        }

        $st->close();
        return $ok;
    }
}

if (!function_exists('ms_sync_session_mentors')) {
    function ms_sync_session_mentors(
        mysqli $conn,
        int $session_id,
        int $organizer_mentor_id,
        array $co_mentor_ids,
        string $invited_by = ''
    ): array {
        if ($session_id <= 0) {
            return ['ok' => false, 'error' => 'Invalid session ID.'];
        }

        if ($organizer_mentor_id <= 0) {
            return ['ok' => false, 'error' => 'Please select the organizing mentor.'];
        }

        $co_mentor_ids = array_values(array_unique(array_filter(
            array_map('intval', $co_mentor_ids),
            static fn($id) => $id > 0 && $id !== $organizer_mentor_id
        )));

        $keep_ids = array_merge([$organizer_mentor_id], $co_mentor_ids);
        $keep_in  = implode(',', array_map('intval', $keep_ids));

        $sid = (int)$session_id;

        $del = $conn->query("
            DELETE FROM session_mentors
            WHERE session_id = {$sid}
              AND mentor_id NOT IN ({$keep_in})
        ");

        if ($del === false) {
            $err = 'Failed to remove old session mentors: ' . $conn->error;
            error_log($err);
            return ['ok' => false, 'error' => $err];
        }

        if (!ms_upsert_session_mentor($conn, $session_id, $organizer_mentor_id, 'organizer', 'accepted', true)) {
            return ['ok' => false, 'error' => 'Failed to save organizing mentor.'];
        }

        foreach ($co_mentor_ids as $mid) {
            if (!ms_upsert_session_mentor($conn, $session_id, $mid, 'co_mentor', 'invited', false)) {
                return ['ok' => false, 'error' => 'Failed to save co-mentor invite.'];
            }
        }

        $st = $conn->prepare("UPDATE mentor_sessions SET mentor_id = ? WHERE id = ?");
        if ($st) {
            $st->bind_param('ii', $organizer_mentor_id, $session_id);
            $st->execute();
            $st->close();
        }

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('ms_sync_session_ventures')) {
    function ms_sync_session_ventures(
        mysqli $conn,
        int $session_id,
        array $venture_ids,
        string $added_by = ''
    ): array {
        if ($session_id <= 0) {
            return ['ok' => false, 'error' => 'Invalid session ID.'];
        }

        $venture_ids = array_values(array_unique(array_filter(
            array_map('intval', $venture_ids),
            static fn($id) => $id > 0
        )));

        if (!$venture_ids) {
            return ['ok' => false, 'error' => 'Please select at least one venture.'];
        }

        $sid = (int)$session_id;
        $keep_in = implode(',', array_map('intval', $venture_ids));

        $del = $conn->query("
            DELETE FROM session_ventures
            WHERE session_id = {$sid}
              AND venture_id NOT IN ({$keep_in})
        ");

        if ($del === false) {
            $err = 'Failed to remove old session ventures: ' . $conn->error;
            error_log($err);
            return ['ok' => false, 'error' => $err];
        }

        foreach ($venture_ids as $vid) {
            $existing_id = 0;

            $st = $conn->prepare("
                SELECT id
                FROM session_ventures
                WHERE session_id = ?
                  AND venture_id = ?
                LIMIT 1
            ");

            if (!$st) {
                $err = 'Failed to check session venture: ' . $conn->error;
                error_log($err);
                return ['ok' => false, 'error' => $err];
            }

            $st->bind_param('ii', $session_id, $vid);
            $st->execute();
            $res = $st->get_result();

            if ($row = $res->fetch_assoc()) {
                $existing_id = (int)$row['id'];
            }

            $st->close();

            if ($existing_id > 0) {
                continue;
            }

            $st = $conn->prepare("
                INSERT INTO session_ventures
                    (session_id, venture_id, created_at)
                VALUES
                    (?, ?, NOW())
            ");

            if (!$st) {
                $err = 'Failed to prepare session venture insert: ' . $conn->error;
                error_log($err);
                return ['ok' => false, 'error' => $err];
            }

            $st->bind_param('ii', $session_id, $vid);

            if (!$st->execute()) {
                $err = 'Failed to insert session venture: ' . $st->error;
                error_log($err);
                $st->close();
                return ['ok' => false, 'error' => $err];
            }

            $st->close();
        }

        $primary_venture_id = (int)$venture_ids[0];
        $st = $conn->prepare("UPDATE mentor_sessions SET venture_id = ? WHERE id = ?");
        if ($st) {
            $st->bind_param('ii', $primary_venture_id, $session_id);
            $st->execute();
            $st->close();
        }

        return ['ok' => true, 'error' => null];
    }
}

if (!function_exists('ms_all_venture_ids')) {
    function ms_all_venture_ids(mysqli $conn): array
    {
        $ids = [];

        $res = $conn->query("SELECT id FROM ventures ORDER BY name ASC");

        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) {
                $ids[] = (int)$r['id'];
            }
        } else {
            error_log('ms_all_venture_ids failed: ' . $conn->error);
        }

        return $ids;
    }
}

if (!function_exists('ms_names_summary')) {
    function ms_names_summary(array $rows, string $key, int $max = 2): string
    {
        $names = [];

        foreach ($rows as $row) {
            $name = trim((string)($row[$key] ?? ''));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        if (!$names) {
            return '';
        }

        if (count($names) <= $max) {
            return implode(', ', $names);
        }

        return implode(', ', array_slice($names, 0, $max)) . ' +' . (count($names) - $max) . ' more';
    }
}