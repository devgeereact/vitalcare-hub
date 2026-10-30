<?php

declare(strict_types=1);

namespace Vitalcare;

/** Server-side Cloudflare Turnstile check. Fails closed: any doubt, error or timeout is a refusal. */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /**
     * @param callable(string,array<string,string>):?string $post posts form fields to a URL and returns the body, or null
     */
    public static function verify(string $secret, string $token, ?string $ip, callable $post): bool
    {
        if ($secret === '' || $token === '' || strlen($token) > 2048) {
            return false;
        }
        $fields = ['secret' => $secret, 'response' => $token];
        if ($ip !== null && $ip !== '') {
            $fields['remoteip'] = $ip;
        }
        try {
            $body = $post(self::VERIFY_URL, $fields);
        } catch (\Throwable) {
            return false;
        }
        $json = is_string($body) ? json_decode($body, true) : null;
        return is_array($json) && ($json['success'] ?? false) === true;
    }

    /** Real HTTPS POST with a short timeout and certificate checking. */
    public static function httpPost(string $url, array $fields): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($fields),
                'timeout' => 4,
                'ignore_errors' => false,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return $body === false ? null : $body;
    }
}
