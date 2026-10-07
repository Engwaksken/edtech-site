<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Uses only the isolated MariaDB test instance on 127.0.0.1:13317.
require_once __DIR__ . '/../includes/backup-encryption.php';
$root = dirname(__DIR__);
$temp = 'C:/Users/HUMBLE~1/AppData/Local/Temp/opencode';
$db = new mysqli('127.0.0.1', 'root', '', '', 13317);
$name = 'edtech_security_checks';
$db->query("CREATE DATABASE IF NOT EXISTS {$name}");
$db->select_db($name);
$password = bin2hex(random_bytes(24));
$db->query("CREATE USER IF NOT EXISTS 'edtech_staging'@'127.0.0.1' IDENTIFIED BY '{$password}'");
$db->query("ALTER USER 'edtech_staging'@'127.0.0.1' IDENTIFIED BY '{$password}'");
$db->query("GRANT ALL ON {$name}.* TO 'edtech_staging'@'127.0.0.1'");
$schemas = [
    'site_settings' => 'setting_key VARCHAR(100) PRIMARY KEY, setting_value TEXT, name VARCHAR(100), value TEXT',
    'ventures' => 'id INT PRIMARY KEY, name VARCHAR(100), status VARCHAR(30), cohort_id INT DEFAULT 0',
    'venture_documents' => 'id INT PRIMARY KEY, venture_id INT, file_path VARCHAR(500)',
    'venture_messages' => 'venture_id INT, is_read INT',
    'admin_users' => 'id INT PRIMARY KEY, full_name VARCHAR(100), username VARCHAR(100), email VARCHAR(100), role VARCHAR(100), photo VARCHAR(200), status VARCHAR(30), can_manage_permissions INT DEFAULT 0',
    'role_permissions' => 'role_key VARCHAR(100), page_key VARCHAR(100), can_access INT',
    'mentors' => 'id INT PRIMARY KEY, user_id INT, email VARCHAR(100), status VARCHAR(30)',
    'mentor_reports' => 'id INT PRIMARY KEY, mentor_id INT, file_path VARCHAR(500)',
    'resources' => 'id INT PRIMARY KEY, file_path VARCHAR(500), access_level VARCHAR(50), status VARCHAR(30)',
    'resource_requests' => 'resource_id INT, venture_id INT, status VARCHAR(30)',
    'session_mentors' => 'session_id INT, mentor_id INT',
    'email_settings' => 'id INT PRIMARY KEY, smtp_password VARCHAR(255), status INT',
    'mentor_google_tokens' => 'mentor_id INT PRIMARY KEY, access_token VARCHAR(255), refresh_token VARCHAR(255)',
    'slides' => 'id INT, page_name VARCHAR(100), status INT, sort_order INT',
    'benefits' => 'id INT, status INT, sort_order INT',
    'program_stages' => 'id INT, status INT, sort_order INT',
    'eligibility_criteria' => 'id INT, status INT, sort_order INT',
    'impact_stats' => 'id INT, status INT, sort_order INT',
    'partners' => 'id INT, status INT, sort_order INT, logo VARCHAR(500)',
    'faqs' => 'id INT, status INT, sort_order INT, question TEXT, answer TEXT',
];
foreach ($schemas as $table => $schema) {
    $db->query("CREATE TABLE IF NOT EXISTS `{$table}` ({$schema})");
    $db->query("DELETE FROM `{$table}`");
}
$db->query("INSERT INTO ventures VALUES (1,'Synthetic Venture One','active',0),(2,'Synthetic Venture Two','active',0),(3,'Inactive Venture','inactive',0)");
$db->query("INSERT INTO admin_users VALUES (1,'Staging Admin','admin','admin@example.test','super-admin','','active',1),(2,'Staging Mentor','mentor','mentor@example.test','mentor','','active',0),(3,'Unprivileged Staff','staff','staff@example.test','reviewer','','active',0)");
$db->query("INSERT INTO mentors VALUES (5,2,'mentor@example.test','active')");
$db->query("INSERT INTO role_permissions VALUES ('mentor','mentor_reports',1)");
$db->query("INSERT INTO faqs VALUES (1,1,1,'Who can apply?','Eligible EdTech ventures can apply to the fellowship.')");
foreach (['site_name'=>'EdTech Fellowship', 'site_logo'=>'assets/images/logo.png', 'site_favicon'=>'assets/images/favicon.png', 'hero_btn_text'=>'Explore the programme', 'hero_application_link'=>'#program'] as $key=>$value) {
    $stmt=$db->prepare('INSERT INTO site_settings (setting_key,setting_value) VALUES (?,?)'); $stmt->bind_param('ss',$key,$value); $stmt->execute(); $stmt->close();
}
$db->query("INSERT INTO email_settings VALUES (1,'synthetic-smtp-secret',1)");
$db->query("INSERT INTO mentor_google_tokens VALUES (5,'synthetic-access-token','synthetic-refresh-token')");
$fixture = 'uploads/ventures/docs/staging_' . bin2hex(random_bytes(8)) . '.txt';
$report = 'uploads/mentor-reports/staging_' . bin2hex(random_bytes(8)) . '.txt';
$otherReport = 'uploads/mentor-reports/staging_' . bin2hex(random_bytes(8)) . '.txt';
$resource = 'uploads/resources/staging_' . bin2hex(random_bytes(8)) . '.txt';
file_put_contents($root . '/' . $fixture, 'Synthetic confidential venture document');
file_put_contents($root . '/' . $report, 'Synthetic confidential mentor report');
file_put_contents($root . '/' . $otherReport, 'Synthetic other-mentor report');
file_put_contents($root . '/' . $resource, 'Synthetic restricted resource');
$stmt=$db->prepare('INSERT INTO venture_documents VALUES (1,1,?)'); $stmt->bind_param('s',$fixture); $stmt->execute(); $stmt->close();
$stmt=$db->prepare('INSERT INTO mentor_reports VALUES (1,5,?)'); $stmt->bind_param('s',$report); $stmt->execute(); $stmt->close();
$stmt=$db->prepare('INSERT INTO mentor_reports VALUES (2,6,?)'); $stmt->bind_param('s',$otherReport); $stmt->execute(); $stmt->close();
$stmt=$db->prepare("INSERT INTO resources VALUES (1,?,'request_required','active')"); $stmt->bind_param('s',$resource); $stmt->execute(); $stmt->close();
$db->query("INSERT INTO resource_requests VALUES (1,1,'approved')");
foreach (['DB_HOST'=>'127.0.0.1','DB_PORT'=>'13317','DB_USER'=>'edtech_staging','DB_PASS'=>$password,'DB_NAME'=>$name,'SITE_URL'=>'http://127.0.0.1:18087','APP_ENCRYPTION_KEY'=>base64_encode(random_bytes(32)),'EDTECH_STAGING'=>'1'] as $key=>$value) putenv($key.'='.$value);

function staging_assert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$cookie = '';
function staging_request(string $path, string $method='GET', string $body='', array $extra=[]): array {
    global $cookie;
    $headers = ['Cookie: '.$cookie, 'Content-Type: application/x-www-form-urlencoded'];
    $context = stream_context_create(['http'=>['method'=>$method,'header'=>implode("\r\n",array_merge($headers,$extra)), 'content'=>$body, 'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0]]);
    $content = file_get_contents('http://127.0.0.1:18087'.$path, false, $context);
    $status=0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+ (\d+)~',$header,$match)) $status=(int)$match[1];
        if (preg_match('~^Set-Cookie: ([^;]+)~i',$header,$match)) $cookie=$match[1];
    }
    return [$status, (string)$content, $http_response_header ?? []];
}

function staging_https_request(string $path, string $method='GET', string $body=''): array {
    global $cookie;
    // Self-signed certificate is accepted only by this isolated loopback test client.
    $context=stream_context_create(['http'=>['method'=>$method,'header'=>"Host: www.edtech.hivecolab.com\r\nCookie: {$cookie}\r\nContent-Type: application/x-www-form-urlencoded",'content'=>$body,'ignore_errors'=>true,'timeout'=>15,'follow_location'=>0], 'ssl'=>['verify_peer'=>false,'verify_peer_name'=>false]]);
    $content=file_get_contents('https://127.0.0.1:18444'.$path,false,$context);
    $status=0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+ (\d+)~',$header,$match)) $status=(int)$match[1];
        if (preg_match('~^Set-Cookie: ([^;]+)~i',$header,$match)) $cookie=$match[1];
    }
    return [$status,(string)$content,$http_response_header ?? []];
}

$log = $temp . '/edtech-staging-server.log';
$process = proc_open([PHP_BINARY,'-S','127.0.0.1:18087','-t',$root,__DIR__.'/staging-router.php'], [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,$root);
$apache = null; $apachePipes = [];
try {
    staging_assert(is_resource($process), 'Could not start staging web server');
    for ($i=0;$i<30;$i++) { $socket=@fsockopen('127.0.0.1',18087); if ($socket) { fclose($socket); break; } usleep(100000); }
    [$status,$html]=staging_request('/__test/form');
    staging_assert($status===200 && preg_match('~name="site_csrf_token" value="([a-f0-9]+)"~',$html,$match), 'Native forms must receive CSRF fields');
    $token=$match[1];
    staging_assert(staging_request('/__test/post','POST')[0]===403, 'Missing CSRF token must be rejected');
    staging_assert(staging_request('/__test/post','POST','site_csrf_token='.$token)[0]===200, 'Native form token must be accepted');
    staging_assert(staging_request('/__test/post','POST','{}',['X-CSRF-Token: '.$token,'Content-Type: application/json'])[0]===200, 'AJAX token header must be accepted');
    staging_assert(staging_request('/__test/post','POST','site_csrf_token='.$token,['Origin: https://attacker.example'])[0]===403, 'Cross-origin POST must be rejected');
    echo "PASS: staging native forms, AJAX, missing-token and cross-origin rejection\n";
    staging_assert(staging_request('/'.$fixture)[0]===404, 'Anonymous file access denied');
    staging_assert(staging_request('/'.$resource)[0]===404, 'Anonymous restricted-resource access denied');
    staging_request('/__test/session?venture=1');
    staging_assert(staging_request('/'.$fixture)[0]===200, 'Venture owner may download');
    staging_assert(staging_request('/'.$resource)[0]===200, 'Approved venture may download restricted resource');
    staging_request('/__test/session?venture=2');
    staging_assert(staging_request('/'.$fixture)[0]===404, 'Different venture denied');
    staging_assert(staging_request('/'.$resource)[0]===404, 'Unapproved venture denied restricted resource');
    staging_request('/__test/session?venture=3');
    staging_assert(staging_request('/'.$fixture)[0]===404, 'Inactive venture denied');
    staging_request('/__test/session?admin=1');
    staging_assert(staging_request('/'.$fixture)[0]===200, 'Super-admin may download');
    staging_request('/__test/session?admin=2');
    staging_assert(staging_request('/'.$report)[0]===200, 'Owning mentor may download');
    staging_assert(staging_request('/'.$otherReport)[0]===404, 'Mentor page permission must not grant another mentor report');
    $db->query("UPDATE mentors SET status='inactive' WHERE id=5");
    staging_assert(staging_request('/'.$report)[0]===404, 'Inactive mentor must lose access even with cached session mentor ID');
    $db->query("UPDATE mentors SET status='active' WHERE id=5");
    staging_assert(staging_request('/'.$fixture)[0]===404, 'Mentor denied unrelated venture document');
    staging_request('/__test/session?admin=3');
    staging_assert(staging_request('/'.$fixture)[0]===404, 'Staff without file permission denied');
    echo "PASS: staging confidential downloads and cross-account access rejection\n";
    foreach (['/logout'=>'venture=1','/admin/logout'=>'admin=1'] as $logout=>$identity) {
        staging_request('/__test/session?'.$identity);
        [$status,$logoutHtml]=staging_request($logout);
        staging_assert($status===200 && staging_request('/'.$fixture)[0]===200,'GET logout must not destroy session');
        staging_assert(staging_request($logout,'POST')[0]===403,'Forged logout must be rejected');
        preg_match('~name="site_csrf_token" value="([a-f0-9]+)"~',$logoutHtml,$logoutToken);
        staging_assert(staging_request($logout,'POST','site_csrf_token='.$logoutToken[1])[0]===302,'Valid logout must redirect');
        staging_assert(staging_request('/'.$fixture)[0]===404,'Valid logout must revoke access');
    }
    echo "PASS: root/admin logout requires a valid POST token\n";
    if (is_file('C:/xampp/apache/bin/httpd.exe')) {
        $apache=proc_open(['C:/xampp/apache/bin/httpd.exe','-f',__DIR__.'/apache-staging.conf'],[0=>['pipe','r'],1=>['file',$temp.'/edtech-apache-console.log','a'],2=>['file',$temp.'/edtech-apache-console.log','a']],$apachePipes,$root);
        for ($i=0;$i<50;$i++) { $socket=@fsockopen('127.0.0.1',18444); if ($socket) { fclose($socket); break; } usleep(100000); }
        $cookie='';
        [$anonymousStatus,$anonymousContent]=staging_https_request('/'.$fixture);
        staging_assert($anonymousStatus===404,'Apache anonymous download returned '.$anonymousStatus.': '.substr($anonymousContent,0,150));
        staging_request('/__test/session?venture=1');
        [$status,$content,$headers]=staging_https_request('/'.$fixture);
        staging_assert($status===200 && $content==='Synthetic confidential venture document','Apache authorized download returned '.$status.': '.substr($content,0,150));
        staging_assert((bool)preg_grep('~^Strict-Transport-Security:~i',$headers),'HTTPS must emit HSTS');
        staging_assert((bool)preg_grep('~^Cache-Control:.*no-store~i',$headers),'Private download must not be cached');
        staging_request('/__test/session?venture=2');
        staging_assert(staging_https_request('/'.$fixture)[0]===404,'Apache must deny another venture');
        staging_assert(staging_https_request('/uploads/ventures/docs/not-registered.txt?path='.$fixture)[0]===404,'Query string must not override rewritten file path');
        staging_assert(staging_https_request('/includes/private-files.php')[0]===403,'Apache must block internal helper');
        [$cliStatus,$cliContent]=staging_https_request('/bin/secure-storage.php');
        staging_assert($cliStatus===403,'Apache CLI utility returned '.$cliStatus.': '.substr($cliContent,0,150));
        staging_assert(staging_https_request('/logout.php','POST')[0]===308,'Canonical PHP redirects must preserve POST');
        echo "PASS: real Apache HTTPS, private-file rewrites, denial rules, HSTS, no-store, and POST-preserving redirects\n";
    }
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/secure-storage.php').' migrate-secrets',$migrationStatus);
    staging_assert($migrationStatus===0, 'Secret migration failed');
    $stored=$db->query('SELECT smtp_password FROM email_settings WHERE id=1')->fetch_assoc()['smtp_password'];
    staging_assert(str_starts_with($stored,'enc:v1:') && site_decrypt_secret($stored,'smtp:password:1')==='synthetic-smtp-secret','Stored SMTP secret encrypted');
    passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/secure-storage.php').' migrate-secrets',$migrationStatus);
    staging_assert($migrationStatus===0, 'Migration must be safe to rerun');
    echo "PASS: staging encrypted-record migration and rerun\n";
    $source=$temp.'/edtech-backup-fixture.sql'; $encrypted=$temp.'/edtech-backup-fixture.edenc'; $restored=$temp.'/edtech-backup-restored.sql';
    foreach ([$source,$encrypted,$restored] as $file) { if (is_file($file)) unlink($file); }
    file_put_contents($source,str_repeat('Synthetic backup content.',60000));
    site_backup_transform($source,$encrypted);
    site_backup_transform($encrypted,$restored,true);
    staging_assert(hash_file('sha256',$source)===hash_file('sha256',$restored),'Chunked backup restore must match original');
    unlink($restored);
    $stream=fopen($encrypted,'r+b'); fseek($stream,-1,SEEK_END); $byte=ord(fread($stream,1)); fseek($stream,-1,SEEK_END); fwrite($stream,chr($byte^1)); fclose($stream);
    try { site_backup_transform($encrypted,$restored,true); staging_assert(false,'Corrupted backup accepted'); } catch (RuntimeException $exception) { staging_assert(!file_exists($restored),'Partial plaintext backup must not be published'); }
    foreach ([$source,$encrypted,$restored] as $file) { if (is_file($file)) unlink($file); }
    echo "PASS: chunked encrypted backup, restore, and tamper rejection\n";
    staging_request('/__test/session');
    foreach (['/','/login','/admin/login','/faqs'] as $page) {
        [$status,$html]=staging_request($page);
        staging_assert($status===200 && !str_contains($html,'Fatal error'),'Staging page failed: '.$page);
    }
    if (getenv('NODE_PATH')) {
        passthru('node '.escapeshellarg(__DIR__.'/browser-check.js'),$browserStatus);
        staging_assert($browserStatus===0,'Browser checks failed');
    }
    echo "All local staging checks passed.\n";
} finally {
    if (is_resource($apache)) {
        $apacheStatus=proc_get_status($apache);
        if ($apacheStatus['running']) exec('taskkill /PID '.(int)$apacheStatus['pid'].' /T /F');
        foreach ($apachePipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($apache);
    }
    if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
    foreach ([$fixture,$report,$otherReport,$resource] as $file) if (is_file($root.'/'.$file)) unlink($root.'/'.$file);
}
