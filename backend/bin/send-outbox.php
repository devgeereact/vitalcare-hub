<?php

declare(strict_types=1);

// Cron job: queue due certificate renewal reminders (D-018), then send queued email. Run every few minutes with an explicit PHP binary, for example
//   */2 * * * * /opt/alt/php84/usr/bin/php ~/vitalcare-private/app/bin/send-outbox.php >> ~/vitalcare-private/logs/outbox.log 2>&1
// Uses the runtime database user. Without a complete smtp section in the private config it does nothing.
// Reminders are idempotent per certificate and stage, so running this every two minutes queues each one once;
// the existing cron entry needs no change.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\Certificates\Renewals;
use Vitalcare\Support\Config;
use Vitalcare\Support\Db;
use Vitalcare\Mail\OutboxSender;
use Vitalcare\Mail\SmtpMailer;

$root = getenv('VITALCARE_PRIVATE_ROOT') ?: (getenv('HOME') ?: '') . '/vitalcare-private';
$c = Config::load($root, getenv('VITALCARE_CONFIG') ?: 'prod');
if ($c['smtp'] === null) {
    exit(0);   // not configured yet: leave everything queued, say nothing
}
$pdo = Db::connect($c['db']);
$q = (new Renewals($pdo, new DateTimeImmutable('now', new DateTimeZone('UTC'))))->queueReminders($c['office_email']);
if ($q['certificates'] > 0) {
    echo gmdate('c'), " reminders certificates={$q['certificates']} emails={$q['emails']}\n";
}
$mailer = new SmtpMailer($c['smtp']);
$r = (new OutboxSender($pdo, $mailer->send(...)))->run();
if ($r['skipped']) {
    exit(0);
}
if ($r['sent'] + $r['failed'] + $r['retry'] > 0) {
    echo gmdate('c'), " sent={$r['sent']} retry={$r['retry']} failed={$r['failed']}\n";
}
exit($r['failed'] > 0 ? 1 : 0);
