<?php

declare(strict_types=1);

use Vitalcare\{ContactService, Db, OutboxSender, SmtpException, SmtpMailer};

/** @var PDO $pdo */
global $host, $port, $name, $user, $pass;

function queue(PDO $pdo, string $to, string $subject = 'S', ?string $reply = null, string $body = 'B', ?string $createdAt = null): string
{
    $id = Db::uuid();
    $pdo->prepare('INSERT INTO outbox_email (id, to_email, reply_to, subject, body_text, created_at) VALUES (?,?,?,?,?, COALESCE(?, CURRENT_TIMESTAMP(3)))')
        ->execute([$id, $to, $reply, $subject, $body, $createdAt]);
    return $id;
}
$row = fn (string $id) => $pdo->query("SELECT * FROM outbox_email WHERE id = '$id'")->fetch();
$calls = [];
$ok = function (string $to, ?string $reply, string $subject, string $body) use (&$calls): void { $calls[] = [$to, $reply, $subject, $body]; };

// ---- happy path ----
$a = queue($pdo, 'info@vitalcare.uk', 'New website enquiry', 'pat@example.com', 'the body');
$r = (new OutboxSender($pdo, $ok))->run();
check('one due message is sent', $r === ['sent' => 1, 'failed' => 0, 'retry' => 0, 'skipped' => false], json_encode($r));
check('the sender is given the right fields', $calls === [['info@vitalcare.uk', 'pat@example.com', 'New website enquiry', 'the body']]);
$x = $row($a);
check('the row is marked sent with a time and no error', $x['status'] === 'sent' && $x['sent_at'] !== null && $x['last_error'] === null && (int) $x['attempts'] === 0);
$calls = [];
(new OutboxSender($pdo, $ok))->run();
check('a sent message is never sent twice', $calls === []);

// ---- not due, other statuses ----
$later = queue($pdo, 'later@example.com');
$pdo->exec("UPDATE outbox_email SET next_attempt_at = DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 1 HOUR) WHERE id = '$later'");
$failedRow = queue($pdo, 'failed@example.com');
$pdo->exec("UPDATE outbox_email SET status = 'failed' WHERE id = '$failedRow'");
(new OutboxSender($pdo, $ok))->run();
check('a message not yet due is left alone', $calls === [] && $row($later)['status'] === 'queued');
check('a failed message is never retried automatically', $row($failedRow)['status'] === 'failed');

// ---- failure and back-off ----
$f = queue($pdo, 'fail@example.com');
$boom = function (): void { throw new SmtpException('The mail server refused: 451 try again later'); };
$r = (new OutboxSender($pdo, $boom))->run();
$x = $row($f);
check('a failure is counted as a retry', $r['retry'] === 1 && $r['sent'] === 0 && $r['failed'] === 0);
check('the row stays queued with one attempt and the server\'s reason', $x['status'] === 'queued' && (int) $x['attempts'] === 1 && str_contains($x['last_error'], '451'));
$secs = fn (string $id) => (int) $pdo->query("SELECT TIMESTAMPDIFF(SECOND, CURRENT_TIMESTAMP(3), next_attempt_at) FROM outbox_email WHERE id = '$id'")->fetchColumn();
check('the first retry is about a minute away', $secs($f) >= 55 && $secs($f) <= 61, (string) $secs($f));
$expected = [2 => [295, 301], 3 => [895, 901], 4 => [3595, 3601]];
foreach ($expected as $attempt => [$lo, $hi]) {
    $pdo->exec("UPDATE outbox_email SET next_attempt_at = CURRENT_TIMESTAMP(3) WHERE id = '$f'");
    (new OutboxSender($pdo, $boom))->run();
    check("attempt $attempt backs off to the right delay", (int) $row($f)['attempts'] === $attempt && $secs($f) >= $lo && $secs($f) <= $hi, (string) $secs($f));
}
$pdo->exec("UPDATE outbox_email SET next_attempt_at = CURRENT_TIMESTAMP(3) WHERE id = '$f'");
$r = (new OutboxSender($pdo, $boom))->run();
$x = $row($f);
check('the fifth failure marks it failed and stops', $r['failed'] === 1 && $x['status'] === 'failed' && (int) $x['attempts'] === 5);
$calls2 = 0;
$pdo->exec("UPDATE outbox_email SET next_attempt_at = CURRENT_TIMESTAMP(3) WHERE id = '$f'");
(new OutboxSender($pdo, function () use (&$calls2): void { $calls2++; }))->run();
check('a failed row is not picked up again', $calls2 === 0);

// ---- secrets never reach the database or the error log ----
$logFile = sys_get_temp_dir() . '/outbox-errlog-' . bin2hex(random_bytes(4));
$prevLog = ini_set('error_log', $logFile);
$s = queue($pdo, 'secret@example.com');
(new OutboxSender($pdo, function (): void { throw new RuntimeException('connect failed password=Mailbox-Pass_9876xyz user=info@vitalcare.uk'); }))->run();
ini_set('error_log', $prevLog === false ? '' : $prevLog);
$logged = (string) @file_get_contents($logFile);
@unlink($logFile);
check('an unexpected exception is logged by class only, never its message', str_contains($logged, 'RuntimeException') && !str_contains($logged, 'Mailbox-Pass') && !str_contains($logged, 'password='), $logged);
$x = $row($s);
check('an unexpected exception is stored as a generic message', $x['last_error'] === 'Unexpected sending error');
check('...so nothing from the exception text, such as a password, is stored', !str_contains(json_encode($x), 'Mailbox-Pass'));

// ---- one failure does not stop the rest; ordering; batch limit ----
$pdo->exec('DELETE FROM outbox_email');
$one = queue($pdo, 'one@example.com', 'S', null, 'B', '2026-01-01 10:00:00.000');
$two = queue($pdo, 'two@example.com', 'S', null, 'B', '2026-01-01 10:00:01.000');
$three = queue($pdo, 'three@example.com', 'S', null, 'B', '2026-01-01 10:00:02.000');
$order = [];
$r = (new OutboxSender($pdo, function (string $to) use (&$order): void { $order[] = $to; if ($to === 'two@example.com') { throw new SmtpException('The mail server refused: 550 no'); } }))->run();
check('messages go oldest first', $order === ['one@example.com', 'two@example.com', 'three@example.com']);
check('one failure does not stop the others', $r['sent'] === 2 && $r['retry'] === 1 && $row($one)['status'] === 'sent' && $row($three)['status'] === 'sent' && $row($two)['status'] === 'queued');
$pdo->exec('DELETE FROM outbox_email');
for ($i = 0; $i < 7; $i++) { queue($pdo, "n$i@example.com"); }
$n = 0;
(new OutboxSender($pdo, function () use (&$n): void { $n++; }, 3))->run();
check('a batch limit is respected', $n === 3);

// ---- only one sender at a time ----
$pdo->exec('DELETE FROM outbox_email');
queue($pdo, 'locked@example.com');
$other = Db::connect(['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'password' => $pass]);
check('another connection can take the sender lock', (int) $other->query("SELECT GET_LOCK('vitalcare_outbox', 0)")->fetchColumn() === 1);
$sentWhileLocked = 0;
$r = (new OutboxSender($pdo, function () use (&$sentWhileLocked): void { $sentWhileLocked++; }))->run();
check('while another sender holds the lock this run does nothing', $r['skipped'] === true && $sentWhileLocked === 0);
$other->query("SELECT RELEASE_LOCK('vitalcare_outbox')");
$r = (new OutboxSender($pdo, function () use (&$sentWhileLocked): void { $sentWhileLocked++; }))->run();
check('once the lock is free the message goes out', $r['skipped'] === false && $sentWhileLocked === 1);
check('the lock is released after a run (even one that failed)', (function () use ($pdo, $other) {
    queue($pdo, 'again@example.com');
    (new OutboxSender($pdo, function (): void { throw new SmtpException('x'); }))->run();
    return (int) $other->query("SELECT GET_LOCK('vitalcare_outbox', 0)")->fetchColumn() === 1;
})());
$other->query("SELECT RELEASE_LOCK('vitalcare_outbox')");

// ---- the whole path: contact form -> outbox -> real SMTP client -> server ----
$pdo->exec('DELETE FROM outbox_email');
$tmp = dirname(__DIR__, 3) . '/.local-test/tmp/outbox-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$smtpPort = random_int(41000, 48999);
$log = "$tmp/log.jsonl";
touch($log);
$p = proc_open([PHP_BINARY, __DIR__ . '/../fake-smtp.php', (string) $smtpPort, $log, 'info@vitalcare.uk', 'Mailbox-Pass_9876xyz', 'ok'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 60; $i++) { $c = @fsockopen('127.0.0.1', $smtpPort, $e, $s, 0.2); if ($c) { fclose($c); break; } usleep(100_000); }
(new ContactService($pdo, 'info@vitalcare.uk'))->store(ContactService::validate(['name' => 'Pat Example', 'email' => 'pat@example.com', 'phone' => '', 'message' => 'Please send details of your first aid courses.']), null);
$mailer = new SmtpMailer(['host' => '127.0.0.1', 'port' => $smtpPort, 'secure' => 'none', 'user' => 'info@vitalcare.uk', 'password' => 'Mailbox-Pass_9876xyz', 'from_email' => 'info@vitalcare.uk', 'from_name' => 'Vitalcare Training Hub']);
$r = (new OutboxSender($pdo, $mailer->send(...)))->run();
check('end to end: the enquiry email is sent', $r['sent'] === 1 && $r['retry'] === 0);
$m = array_values(array_filter(array_map(fn ($l) => json_decode($l, true), explode("\n", (string) file_get_contents($log)))));
check('end to end: the server got it addressed to the office with the visitor as Reply-To', count($m) === 1 && $m[0]['rcpt'] === ['info@vitalcare.uk'] && str_contains($m[0]['data'], 'Reply-To: <pat@example.com>'));
[, $b64] = explode("\n\n", $m[0]['data'], 2);
check('end to end: the body carries the enquirer\'s message', str_contains(base64_decode(str_replace("\n", '', $b64)), 'first aid courses'));
check('end to end: the row is marked sent', $pdo->query("SELECT status FROM outbox_email")->fetchColumn() === 'sent');
proc_terminate($p); proc_close($p);

// ---- the cron entry point does nothing, safely, when mail is not configured ----
$root = "$tmp/private"; mkdir("$root/config", 0700, true);
queue($pdo, 'untouched@example.com');
file_put_contents("$root/config/prod.php", "<?php return " . var_export([
    'db' => ['dsn' => "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4", 'user' => $user, 'password' => $pass],
    'clerk' => ['issuer' => 'https://clerk.vitalcare.uk', 'authorized_parties' => ['https://vitalcare.uk']],
    'smtp' => ['password' => 'only-a-password-1234'],
], true) . ";\n");
$cp = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/bin/send-outbox.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['VITALCARE_PRIVATE_ROOT' => $root, 'PATH' => getenv('PATH') ?: '']);
$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
check('send-outbox.php without a complete smtp section exits 0 and says nothing', proc_close($cp) === 0 && $out === '', $out);
check('...and leaves the queue untouched', (int) $pdo->query("SELECT COUNT(*) FROM outbox_email WHERE to_email = 'untouched@example.com' AND status = 'queued' AND attempts = 0")->fetchColumn() === 1);
unlink("$root/config/prod.php"); rmdir("$root/config"); rmdir($root);
foreach (glob("$tmp/*") ?: [] as $f2) { unlink($f2); }
rmdir($tmp);
