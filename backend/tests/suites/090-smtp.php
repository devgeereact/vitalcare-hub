<?php

declare(strict_types=1);

use Vitalcare\Mail\SmtpException;
use Vitalcare\Mail\SmtpMailer;

$tmp = dirname(__DIR__, 3) . '/.local-test/tmp/smtp-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$procs = [];

/** Starts a fake server and returns [port, logfile]. */
$serve = function (string $mode, string $user = 'info@vitalcare.uk', string $pass = 'Mailbox-Pass_9876xyz') use ($tmp, &$procs): array {
    $port = random_int(41000, 48999);
    $log = "$tmp/log-$port.jsonl";
    touch($log);
    $p = proc_open([PHP_BINARY, __DIR__ . '/../fake-smtp.php', (string) $port, $log, $user, $pass, $mode], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 60; $i++) {
        $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
        if ($c) { fclose($c); break; }
        usleep(100_000);
    }
    $procs[] = $p;
    return [$port, $log];
};
$cfg = fn (int $port, array $o = []) => array_merge(['host' => '127.0.0.1', 'port' => $port, 'secure' => 'none', 'user' => 'info@vitalcare.uk', 'password' => 'Mailbox-Pass_9876xyz', 'from_email' => 'info@vitalcare.uk', 'from_name' => 'Vitalcare Training Hub'], $o);
$sent = fn (string $log) => array_map(fn ($l) => json_decode($l, true), array_filter(explode("\n", (string) file_get_contents($log))));
$fails = function (string $label, callable $fn, ?string $contains = null) {
    try { $fn(); check($label, false, 'no exception'); }
    catch (SmtpException $e) { check($label, $contains === null || str_contains($e->getMessage(), $contains), $e->getMessage()); }
};

// ---- a normal send ----
[$port, $log] = $serve('ok');
(new SmtpMailer($cfg($port)))->send('info@vitalcare.uk', 'pat@example.com', 'New website enquiry', "Name: Pat\n\nHello, this is the message.\n");
$m = $sent($log);
check('the server received exactly one message', count($m) === 1);
check('MAIL FROM is the configured sender', $m[0]['from'] === 'info@vitalcare.uk');
check('RCPT TO is the recipient', $m[0]['rcpt'] === ['info@vitalcare.uk']);
$raw = $m[0]['data'];
check('From carries the display name and the address', str_contains($raw, 'From: Vitalcare Training Hub <info@vitalcare.uk>'));
check('To, Reply-To and Subject are present', str_contains($raw, "To: <info@vitalcare.uk>\n") && str_contains($raw, "Reply-To: <pat@example.com>\n") && str_contains($raw, "Subject: New website enquiry\n"));
check('it is marked as UTF-8 base64 plain text', str_contains($raw, "Content-Type: text/plain; charset=UTF-8\n") && str_contains($raw, "Content-Transfer-Encoding: base64\n"));
check('it has a Date and a Message-ID on our domain', preg_match('/^Date: .+ \+0000$/m', $raw) === 1 && preg_match('/^Message-ID: <[0-9a-f-]{36}@vitalcare\.uk>$/m', $raw) === 1);
check('it says it is auto-generated', str_contains($raw, "Auto-Submitted: auto-generated\n"));
[$head, $bodyB64] = explode("\n\n", $raw, 2);
check('the body decodes to exactly what was sent', base64_decode(str_replace("\n", '', $bodyB64)) === "Name: Pat\n\nHello, this is the message.\n");
check('no body line exceeds 76 characters', max(array_map('strlen', explode("\n", trim($bodyB64)))) <= 76);
check('the password is nowhere in what the server received', !str_contains(json_encode($m), 'Mailbox-Pass_9876xyz'));

// ---- unusual content survives ----
[$port, $log] = $serve('ok');
$tricky = "Line one\n.\n..dot-leading\n\nUnicode: café, 日本語, emoji \u{1F600}\n" . str_repeat('long ', 500) . "\nBcc: evil@example.com\r\nX-Injected: yes\n";
(new SmtpMailer($cfg($port)))->send('info@vitalcare.uk', null, 'Café enquiry: 日本語', $tricky);
$raw = $sent($log)[0]['data'];
[$head, $bodyB64] = explode("\n\n", $raw, 2);
check('a lone dot, leading dots, unicode and a long line survive intact', base64_decode(str_replace("\n", '', $bodyB64)) === $tricky);
check('"Bcc:" inside the body is only body text, never a header', !str_contains($head, 'Bcc:') && !str_contains($head, 'X-Injected'));
check('a non-ASCII subject is RFC 2047 encoded', preg_match('/^Subject: =\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/m', $head) === 1 && str_contains(base64_decode(substr(strstr($head, 'Subject: =?UTF-8?B?'), 19, strpos(substr(strstr($head, 'Subject: =?UTF-8?B?'), 19), '?=') )), 'Café'));
check('no Reply-To header when none is given', !str_contains($head, 'Reply-To:'));

// ---- header injection is refused before anything is sent ----
[$port, $log] = $serve('ok');
$mailer = new SmtpMailer($cfg($port));
$fails('a line break in the recipient is refused', fn () => $mailer->send("a@example.com\r\nBcc: x@example.com", null, 's', 'b'), 'line break');
$fails('a line break in Reply-To is refused', fn () => $mailer->send('a@example.com', "p@example.com\nBcc: x@example.com", 's', 'b'), 'line break');
$fails('a line break in the subject is refused', fn () => $mailer->send('a@example.com', null, "Hi\r\nBcc: x@example.com", 'b'), 'line break');
$fails('a null byte in the subject is refused', fn () => $mailer->send('a@example.com', null, "Hi\0there", 'b'), 'line break');
$fails('an invalid recipient is refused', fn () => $mailer->send('not-an-address', null, 's', 'b'), 'invalid address');
$fails('an invalid Reply-To is refused', fn () => $mailer->send('a@example.com', 'nope', 's', 'b'), 'invalid address');
$fails('a configured sender name with a line break is refused', fn () => (new SmtpMailer($cfg($port, ['from_name' => "Evil\r\nBcc: x@example.com"])))->send('a@example.com', null, 's', 'b'), 'line break');
check('none of the refused sends reached the server', $sent($log) === []);

// ---- server-side failures: clear, secret-free messages ----
[$port, $log] = $serve('reject_auth');
$fails('a rejected login is reported', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), '535');
try { (new SmtpMailer($cfg($port, ['password' => 'Wrong-Password-Value-1'])))->send('a@example.com', null, 's', 'b'); } catch (SmtpException $e) {
    check('the failure message never contains the password', !str_contains($e->getMessage(), 'Wrong-Password-Value-1') && !str_contains($e->getMessage(), base64_encode('Wrong-Password-Value-1')));
    check('...nor the user name in encoded form', !str_contains($e->getMessage(), base64_encode('info@vitalcare.uk')));
}
[$port] = $serve('ok', 'someone-else@example.com', 'x');
$fails('the wrong user name is rejected', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), '535');
[$port] = $serve('reject_rcpt');
$fails('a rejected recipient is reported with the server code', fn () => (new SmtpMailer($cfg($port)))->send('nobody@example.com', null, 's', 'b'), '550');
[$port] = $serve('reject_data');
$fails('a rejected message body is reported', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), '550');
[$port] = $serve('no_auth');
$fails('a server that offers no authentication is refused, nothing sent', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), 'authentication');
[$port] = $serve('drop_after_greeting');
$fails('a dropped connection is reported', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), 'closed');
[$port] = $serve('bad_greeting');
$fails('a refused greeting is reported', fn () => (new SmtpMailer($cfg($port)))->send('a@example.com', null, 's', 'b'), '554');
[$port] = $serve('slow');
$t = microtime(true);
$fails('a stalled server times out instead of hanging', fn () => (new SmtpMailer($cfg($port), 1))->send('a@example.com', null, 's', 'b'), 'timed out');
check('...within a couple of seconds', microtime(true) - $t < 3.5, (string) round(microtime(true) - $t, 1));
$fails('nothing listening: reported without a stack trace or host details', fn () => (new SmtpMailer($cfg(1)))->send('a@example.com', null, 's', 'b'), 'Could not connect');

// ---- TLS is on by default for real configurations ----
$fails('implicit TLS against a plain-text server fails closed (certificate and handshake are checked)', fn () => (new SmtpMailer($cfg($serve('ok')[0], ['secure' => 'ssl']), 2))->send('a@example.com', null, 's', 'b'));
$fails('STARTTLS against a server that does not offer it fails closed', fn () => (new SmtpMailer($cfg($serve('ok')[0], ['secure' => 'tls']), 2))->send('a@example.com', null, 's', 'b'));

// ---- certificate verification: a server with a self-signed certificate must be REFUSED ----
// (With verification off the handshake would succeed and the login would be sent to an untrusted server.)
$pem = "$tmp/selfsigned.pem";
$key = openssl_pkey_new(['private_key_bits' => 2048]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key);
$crt = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($crt, $certPem);
openssl_pkey_export($key, $keyPem);
file_put_contents($pem, $certPem . $keyPem);
$tlsPort = random_int(41000, 48999);
$tlsLog = "$tmp/tls.jsonl"; touch($tlsLog);
$tlsProc = proc_open([PHP_BINARY, __DIR__ . '/../fake-smtp.php', (string) $tlsPort, $tlsLog, 'info@vitalcare.uk', 'Mailbox-Pass_9876xyz', 'ok'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, ['FAKE_TLS_CERT' => $pem, 'PATH' => getenv('PATH') ?: '']);
for ($i = 0; $i < 60; $i++) { $c = @stream_socket_client("tcp://127.0.0.1:$tlsPort", $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100_000); }
$fails('a self-signed certificate is refused (verification is on)', fn () => (new SmtpMailer($cfg($tlsPort, ['secure' => 'ssl']), 3))->send('a@example.com', null, 's', 'b'), 'Could not connect');
check('...and no credentials or message reached that server', $sent($tlsLog) === []);
proc_terminate($tlsProc); proc_close($tlsProc);

foreach ($procs as $p) { proc_terminate($p); proc_close($p); }
foreach (glob("$tmp/*") ?: [] as $f) { unlink($f); }
rmdir($tmp);
