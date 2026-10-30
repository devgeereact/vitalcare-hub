<?php

declare(strict_types=1);

// Cron job: send queued email. Run every few minutes with an explicit PHP binary, for example
//   */2 * * * * /opt/alt/php84/usr/bin/php ~/vitalcare-private/app/bin/send-outbox.php >> ~/vitalcare-private/logs/outbox.log 2>&1
// Uses the runtime database user. Without a complete smtp section in the private config it does nothing.
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require_once __DIR__ . '/../src/Bootstrap.php';

use Vitalcare\{Config, Db, OutboxSender, SmtpMailer};

$root = getenv('VITALCARE_PRIVATE_ROOT') ?: (getenv('HOME') ?: '') . '/vitalcare-private';
$c = Config::load($root, getenv('VITALCARE_CONFIG') ?: 'prod');
if ($c['smtp'] === null) {
    exit(0);   // not configured yet: leave everything queued, say nothing
}
$mailer = new SmtpMailer($c['smtp']);
$r = (new OutboxSender(Db::connect($c['db']), $mailer->send(...)))->run();
if ($r['skipped']) {
    exit(0);
}
if ($r['sent'] + $r['failed'] + $r['retry'] > 0) {
    echo gmdate('c'), " sent={$r['sent']} retry={$r['retry']} failed={$r['failed']}\n";
}
exit($r['failed'] > 0 ? 1 : 0);
