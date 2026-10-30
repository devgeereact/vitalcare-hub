<?php

declare(strict_types=1);

// A scriptable fake SMTP server for tests. php fake-smtp.php PORT LOGFILE USER PASS MODE
// Modes: ok | reject_auth | reject_rcpt | drop_after_greeting | slow | no_auth | bad_greeting | reject_data
// Each accepted message is appended to LOGFILE as one JSON line: {"user","from","rcpt","data"}.
[, $port, $log, $user, $pass, $mode] = $argv + [null, 0, '/dev/null', 'u', 'p', 'ok'];
// With FAKE_TLS_CERT set (a PEM holding certificate and key) the server speaks implicit TLS.
$cert = getenv('FAKE_TLS_CERT') ?: null;
$ctx = $cert ? stream_context_create(['ssl' => ['local_cert' => $cert, 'verify_peer' => false, 'allow_self_signed' => true]]) : stream_context_create();
$srv = stream_socket_server(($cert ? 'ssl' : 'tcp') . "://127.0.0.1:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($srv === false) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
$say = static fn ($c, string $s) => fwrite($c, $s . "\r\n");
$idleSince = microtime(true);
// accept() also returns false when a client fails the TLS handshake (for example a readiness probe, or a
// client that rejects our self-signed certificate). That must not end the server: keep going until idle 60 s.
while (true) {
    $c = @stream_socket_accept($srv, 2);
    if ($c === false) {
        if (microtime(true) - $idleSince > 60) { break; }
        continue;
    }
    $idleSince = microtime(true);
    stream_set_timeout($c, 5);
    if ($mode === 'slow') { sleep(4); }
    if ($mode === 'drop_after_greeting') { $say($c, '220 fake ESMTP'); fclose($c); continue; }
    if ($mode === 'bad_greeting') { $say($c, '554 no service'); fclose($c); continue; }
    $say($c, '220 fake ESMTP ready');
    $st = ['auth' => 0, 'authed' => false, 'from' => null, 'rcpt' => [], 'data' => false, 'buf' => ''];
    while (($line = fgets($c, 8192)) !== false) {
        $l = rtrim($line, "\r\n");
        if ($st['data']) {
            if ($l === '.') {
                $st['data'] = false;
                if ($mode === 'reject_data') { $say($c, '550 content rejected'); continue; }
                file_put_contents($log, json_encode(['user' => $user, 'from' => $st['from'], 'rcpt' => $st['rcpt'], 'data' => $st['buf']]) . "\n", FILE_APPEND);
                $say($c, '250 queued');
                $st['buf'] = ''; $st['rcpt'] = [];
            } else {
                $st['buf'] .= (str_starts_with($l, '..') ? substr($l, 1) : $l) . "\n";
            }
            continue;
        }
        if ($st['auth'] === 1) {
            $st['auth'] = base64_decode($l) === $user ? 2 : -1;
            $say($c, '334 UGFzc3dvcmQ6');
            continue;
        }
        if ($st['auth'] === 2 || $st['auth'] === -1) {
            $ok = $mode !== 'reject_auth' && $st['auth'] === 2 && base64_decode($l) === $pass;
            $st['authed'] = $ok; $st['auth'] = 0;
            $say($c, $ok ? '235 2.7.0 Authentication succeeded' : '535 5.7.8 Authentication credentials invalid');
            continue;
        }
        $u = strtoupper($l);
        if (str_starts_with($u, 'EHLO')) {
            $say($c, '250-fake greets you');
            $say($c, '250-SIZE 10485760');
            $say($c, $mode === 'no_auth' ? '250 8BITMIME' : '250-8BITMIME');
            if ($mode !== 'no_auth') { $say($c, '250 AUTH LOGIN PLAIN'); }
        } elseif ($u === 'AUTH LOGIN') { $st['auth'] = 1; $say($c, '334 VXNlcm5hbWU6');
        } elseif (str_starts_with($u, 'MAIL FROM:')) { $st['from'] = trim(substr($l, 10), '<> '); $say($c, $st['authed'] ? '250 ok' : '530 authentication required');
        } elseif (str_starts_with($u, 'RCPT TO:')) {
            if ($mode === 'reject_rcpt') { $say($c, '550 5.1.1 no such user here'); } else { $st['rcpt'][] = trim(substr($l, 8), '<> '); $say($c, '250 ok'); }
        } elseif ($u === 'DATA') { $st['data'] = true; $say($c, '354 go ahead');
        } elseif ($u === 'QUIT') { $say($c, '221 bye'); break;
        } else { $say($c, '502 unknown'); }
    }
    @fclose($c);
}
