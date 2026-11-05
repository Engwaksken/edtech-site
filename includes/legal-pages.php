<?php
declare(strict_types=1);

/*
 * Privacy policy and terms of service: admin-edited under
 * Site Settings > Legal Pages, shown at /privacy-policy and /terms-of-service.
 */

const LEGAL_PAGES = [
    'privacy' => [
        'title'    => 'Privacy Policy',
        'path'     => 'privacy-policy',
        'setting'  => 'legal_privacy_html',
        'updated'  => 'legal_privacy_updated',
        'icon'     => 'fa-user-shield',
    ],
    'terms' => [
        'title'    => 'Terms of Service',
        'path'     => 'terms-of-service',
        'setting'  => 'legal_terms_html',
        'updated'  => 'legal_terms_updated',
        'icon'     => 'fa-file-contract',
    ],
];

/** Starter text an admin can load into the editor, review and publish. */
function legal_page_starter(string $page, string $siteName, string $email): string
{
    $n = htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8');
    $e = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

    if ($page === 'privacy') {
        return <<<HTML
<p>This Privacy Policy explains how {$n} ("we", "us") collects, uses and protects personal information when you use this website and its fellow, mentor and administrator portals.</p>

<h2>Information we collect</h2>
<ul>
  <li><strong>Account and profile details</strong> such as your name, email address, phone number, organisation and role.</li>
  <li><strong>Programme information</strong> you submit, such as applications, venture details, reports, documents, survey and interview responses.</li>
  <li><strong>Usage information</strong> such as log-in times, pages visited and basic device and browser details, used to keep the service secure and working.</li>
</ul>

<h2>How we use information</h2>
<ul>
  <li>To run the fellowship programme, including applications, mentoring sessions, events and reporting.</li>
  <li>To communicate with you about the programme.</li>
  <li>To keep the platform secure and to meet our legal and funder reporting obligations.</li>
</ul>

<h2>Google user data</h2>
<p>Mentors can choose to connect a Google account so that mentoring sessions are added to their Google Calendar. When you connect, we request permission to manage calendars and to read your Google account email address.</p>
<ul>
  <li>We use this access only to create a dedicated "Mentoring Sessions" calendar and to add, update and remove session events in it, including sending invitations to session participants.</li>
  <li>We store the access tokens Google provides, encrypted, only for as long as your calendar stays connected.</li>
  <li>We do not sell Google user data, use it for advertising, or share it with third parties except as needed to provide this feature.</li>
  <li>You can disconnect at any time from the calendar settings in your portal, or by removing access in your Google Account under Security &gt; Third-party access.</li>
</ul>
<p>Our use of information received from Google APIs adheres to the <a href="https://developers.google.com/terms/api-services-user-data-policy">Google API Services User Data Policy</a>, including the Limited Use requirements.</p>

<h2>Sharing</h2>
<p>We share information only with programme partners and service providers who help us run the programme, under appropriate confidentiality obligations, or where the law requires it.</p>

<h2>Retention and security</h2>
<p>We keep personal information only as long as needed for the programme and our legal obligations, and we protect it with access controls and encryption.</p>

<h2>Your choices</h2>
<p>You can ask to access, correct or delete your personal information by contacting us.</p>

<h2>Contact</h2>
<p>Questions about this policy: <a href="mailto:{$e}">{$e}</a></p>
HTML;
    }

    return <<<HTML
<p>These Terms of Service govern your use of the {$n} website and its fellow, mentor and administrator portals. By using the service you agree to these terms.</p>

<h2>Using the service</h2>
<ul>
  <li>You must provide accurate information and keep your log-in details private.</li>
  <li>You are responsible for activity under your account.</li>
  <li>You must not misuse the service, attempt to access data that is not yours, or interfere with its operation.</li>
</ul>

<h2>Your content</h2>
<p>You keep ownership of the material you submit. You allow us to use it to run the programme and for programme reporting to our partners.</p>

<h2>Connected services</h2>
<p>Some features, such as Google Calendar, connect to third-party services. Your use of those services is also subject to their own terms. You can disconnect them at any time.</p>

<h2>Availability</h2>
<p>We aim to keep the service available but do not guarantee it will be uninterrupted or error-free. We may change or suspend features.</p>

<h2>Ending access</h2>
<p>We may suspend or end access for accounts that break these terms or are no longer part of the programme.</p>

<h2>Changes</h2>
<p>We may update these terms. The date at the top of this page shows when they last changed.</p>

<h2>Contact</h2>
<p>Questions about these terms: <a href="mailto:{$e}">{$e}</a></p>
HTML;
}
