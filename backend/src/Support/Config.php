<?php

declare(strict_types=1);

namespace Vitalcare\Support;

use Vitalcare\Auth\SessionResolver;

/**
 * Private runtime configuration. The file lives outside every webroot
 * (production: ~/vitalcare-private/config/prod.php, mode 0600) and is never committed.
 * See backend/config.example.php for the shape; it holds names and placeholders only.
 */
final class Config
{
    /**
     * @return array{
     *   db: array{dsn:string,user:string,password:string},
     *   migrate: array{dsn:string,user:string,password:string},
     *   clerk: array{issuer:string,authorized_parties:list<string>},
     *   office_email: string,
     *   turnstile: array{secret:string,dev_bypass:bool},
     *   smtp: ?array{host:string,port:int,secure:string,user:string,password:string,from_email:string,from_name:string}
     * }
     */
    public static function load(string $privateRoot, string $name = 'prod'): array
    {
        if (preg_match('/^[a-z0-9_-]+$/', $name) !== 1) {
            throw new \RuntimeException('Invalid configuration name');
        }
        $file = rtrim($privateRoot, '/') . "/config/$name.php";
        if (!is_file($file) || !is_readable($file)) {
            throw new \RuntimeException('Configuration file is missing or unreadable');
        }
        $c = require $file;
        if (!is_array($c)) {
            throw new \RuntimeException('Configuration file must return an array');
        }
        foreach (['db', 'clerk'] as $section) {
            if (!isset($c[$section]) || !is_array($c[$section])) {
                throw new \RuntimeException("Configuration section missing: $section");
            }
        }
        foreach (['dsn', 'user', 'password'] as $k) {
            if (!isset($c['db'][$k]) || !is_string($c['db'][$k]) || $c['db'][$k] === '') {
                throw new \RuntimeException("Configuration db.$k missing");
            }
        }
        $issuer = $c['clerk']['issuer'] ?? null;
        $parties = $c['clerk']['authorized_parties'] ?? null;
        if (!is_string($issuer) || !is_array($parties) || $parties === []) {
            throw new \RuntimeException('Configuration clerk.issuer and clerk.authorized_parties are required');
        }
        // Only a Clerk DEVELOPMENT instance (issuer *.clerk.accounts.dev) may also list the local Vite origin over
        // plain http on the loopback address, for `pnpm dev:local`. A production issuer never can.
        $devIssuer = preg_match('#^https://[a-z0-9-]+\.clerk\.accounts\.dev\z#', $issuer) === 1;
        foreach ($parties as $p) {
            $https = is_string($p) && str_starts_with($p, 'https://') && !str_ends_with($p, '/');
            $loopback = $devIssuer && is_string($p) && preg_match('#^http://(127\.0\.0\.1|localhost):[0-9]{2,5}\z#', $p) === 1;
            if (!$https && !$loopback) {
                throw new \RuntimeException('Authorised parties must be https origins without a trailing slash');
            }
        }
        $c['migrate'] = isset($c['migrate']) && is_array($c['migrate']) ? $c['migrate'] : $c['db'];

        $office = $c['office_email'] ?? 'info@vitalcare.uk';
        if (!is_string($office) || SessionResolver::normaliseEmail($office) === null) {
            throw new \RuntimeException('Configuration office_email must be a valid email address');
        }
        $c['office_email'] = $office;

        $t = isset($c['turnstile']) && is_array($c['turnstile']) ? $c['turnstile'] : [];
        $c['turnstile'] = [
            'secret' => is_string($t['secret'] ?? null) ? $t['secret'] : '',
            // Local development only. A production config must never set this.
            'dev_bypass' => ($t['dev_bypass'] ?? false) === true,
        ];

        // Optional. Without a complete, valid smtp section the outbox sender does nothing and mail stays queued.
        $c['smtp'] = self::smtp($c['smtp'] ?? null);
        return $c;
    }

    /**
     * The mail-sending settings, or null unless every one of them is present and valid. Deliberately lenient:
     * a half-finished smtp section must disable mail sending only, never the API (a throw here would put the
     * whole API into its "unconfigured" mode).
     *
     * @return array{host:string,port:int,secure:string,user:string,password:string,from_email:string,from_name:string}|null
     */
    private static function smtp(mixed $s): ?array
    {
        if (!is_array($s)) {
            return null;
        }
        foreach (['host', 'user', 'password', 'from_email', 'from_name', 'secure'] as $k) {
            if (!isset($s[$k]) || !is_string($s[$k]) || $s[$k] === '') {
                return null;
            }
        }
        if (($s['port'] ?? null) !== 465 && ($s['port'] ?? null) !== 587) {
            return null;
        }
        if (!in_array($s['secure'], ['ssl', 'tls'], true)
            || SessionResolver::normaliseEmail($s['user']) === null
            || SessionResolver::normaliseEmail($s['from_email']) === null) {
            return null;
        }
        return [
            'host' => $s['host'], 'port' => $s['port'], 'secure' => $s['secure'], 'user' => $s['user'],
            'password' => $s['password'], 'from_email' => $s['from_email'], 'from_name' => $s['from_name'],
        ];
    }
}
