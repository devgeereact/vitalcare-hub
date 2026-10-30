<?php

declare(strict_types=1);

namespace Vitalcare;

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
     *   turnstile: array{secret:string,dev_bypass:bool}
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
        foreach ($parties as $p) {
            if (!is_string($p) || !str_starts_with($p, 'https://') || str_ends_with($p, '/')) {
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
        return $c;
    }
}
