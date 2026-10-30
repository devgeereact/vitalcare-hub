<?php

declare(strict_types=1);

namespace Vitalcare;

/**
 * A small SMTP client for one purpose: send plain-text mail through the hosting account's mail server.
 *
 * - Implicit TLS on 465 ("ssl") or STARTTLS on 587 ("tls"), with certificate and host-name verification on.
 *   Mode "none" exists only so tests can talk to a fake server on 127.0.0.1; Config never produces it.
 * - AUTH LOGIN. Credentials are sent but never logged, and never appear in any exception message.
 * - Every header value is checked for CR and LF before anything is sent (header injection).
 * - The body is base64 (UTF-8), so no line can be a lone "." and long lines are safe.
 * - Every wait has a timeout. Any failure throws SmtpException with a short, secret-free message.
 */
final class SmtpMailer
{
    /** @param array{host:string,port:int,secure:string,user:string,password:string,from_email:string,from_name:string} $cfg */
    public function __construct(private readonly array $cfg, private readonly int $timeout = 10, private readonly string $ehloName = 'vitalcare.uk')
    {
    }

    public function send(string $to, ?string $replyTo, string $subject, string $body): void
    {
        foreach ([$to, $replyTo ?? '', $subject, $this->cfg['from_email'], $this->cfg['from_name']] as $v) {
            if (preg_match('/[\r\n\0]/', $v) === 1) {
                throw new SmtpException('Refused: a header value contains a line break');
            }
        }
        if (SessionResolver::normaliseEmail($to) === null || ($replyTo !== null && SessionResolver::normaliseEmail($replyTo) === null)) {
            throw new SmtpException('Refused: invalid address');
        }

        $secure = $this->cfg['secure'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $this->cfg['host'], 'SNI_enabled' => true]]);
        $scheme = $secure === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $fp = @stream_socket_client("$scheme://{$this->cfg['host']}:{$this->cfg['port']}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($fp === false) {
            throw new SmtpException('Could not connect to the mail server');
        }
        stream_set_timeout($fp, $this->timeout);
        try {
            $this->expect($fp, [220]);
            $caps = $this->ehlo($fp);
            if ($secure === 'tls') {
                $this->command($fp, 'STARTTLS', [220]);
                if (@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT) !== true) {
                    throw new SmtpException('The mail server did not complete TLS');
                }
                $caps = $this->ehlo($fp);
            }
            if (!str_contains($caps, 'AUTH')) {
                throw new SmtpException('The mail server does not offer authentication');
            }
            $this->command($fp, 'AUTH LOGIN', [334]);
            $this->command($fp, base64_encode($this->cfg['user']), [334], true);
            $this->command($fp, base64_encode($this->cfg['password']), [235], true);
            $this->command($fp, 'MAIL FROM:<' . $this->cfg['from_email'] . '>', [250]);
            $this->command($fp, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($fp, 'DATA', [354]);
            $this->write($fp, $this->message($to, $replyTo, $subject, $body) . "\r\n.\r\n");
            $this->expect($fp, [250]);
            $this->command($fp, 'QUIT', [221]);
        } finally {
            @fclose($fp);
        }
    }

    private function message(string $to, ?string $replyTo, string $subject, string $body): string
    {
        $from = $this->cfg['from_email'];
        $name = $this->cfg['from_name'];
        $h = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . self::encodeHeader($name) . ' <' . $from . '>',
            'To: <' . $to . '>',
        ];
        if ($replyTo !== null) {
            $h[] = 'Reply-To: <' . $replyTo . '>';
        }
        $h[] = 'Subject: ' . self::encodeHeader($subject);
        $h[] = 'Message-ID: <' . Db::uuid() . '@' . substr(strrchr($from, '@') ?: '@vitalcare.uk', 1) . '>';
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/plain; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        $h[] = 'Auto-Submitted: auto-generated';
        return implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body), 76, "\r\n");
    }

    /** RFC 2047 encoded-word for anything that is not plain printable ASCII. */
    public static function encodeHeader(string $v): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $v) === 1 ? $v : '=?UTF-8?B?' . base64_encode($v) . '?=';
    }

    /** @param resource $fp */
    private function ehlo($fp): string
    {
        return $this->command($fp, 'EHLO ' . $this->ehloName, [250]);
    }

    /**
     * @param resource $fp
     * @param list<int> $ok
     */
    private function command($fp, string $line, array $ok, bool $secret = false): string
    {
        $this->write($fp, $line . "\r\n");
        return $this->expect($fp, $ok);
    }

    /** @param resource $fp */
    private function write($fp, string $data): void
    {
        $len = strlen($data);
        $done = 0;
        while ($done < $len) {
            $n = @fwrite($fp, substr($data, $done));
            if ($n === false || $n === 0) {
                throw new SmtpException('Lost the connection to the mail server');
            }
            $done += $n;
        }
    }

    /**
     * Reads one (possibly multi-line) reply and checks its code.
     *
     * @param resource $fp
     * @param list<int> $ok
     */
    private function expect($fp, array $ok): string
    {
        $text = '';
        $code = 0;
        for ($i = 0; $i < 50; $i++) {
            $line = fgets($fp, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($fp);
                throw new SmtpException(($meta['timed_out'] ?? false) ? 'The mail server timed out' : 'The mail server closed the connection');
            }
            if (preg_match('/^(\d{3})([ -])(.*)$/s', rtrim($line, "\r\n") . "\n", $m) !== 1) {
                throw new SmtpException('Unexpected reply from the mail server');
            }
            $code = (int) $m[1];
            $text .= $m[3] . "\n";
            if ($m[2] === ' ') {
                if (!in_array($code, $ok, true)) {
                    // The server's own words, trimmed. They name the problem and never contain what we sent.
                    throw new SmtpException('The mail server refused: ' . $code . ' ' . substr(trim($m[3]), 0, 120), $code);
                }
                return $text;
            }
        }
        throw new SmtpException('The mail server sent too long a reply');
    }
}
