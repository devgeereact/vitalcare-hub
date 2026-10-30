<?php

declare(strict_types=1);

namespace Vitalcare;

use Vitalcare\Auth\Jwt;
use Vitalcare\Http\Api;
use Vitalcare\Http\HttpError;
use Vitalcare\Security\Turnstile;
use Vitalcare\Support\Config;
use Vitalcare\Support\Db;

require_once __DIR__ . '/Autoload.php';

/** Builds the Api from the private configuration. Used by the front controller and the dev server. */
final class Bootstrap
{
    public static function api(string $privateRoot, string $configName = 'prod'): Api
    {
        try {
            $c = Config::load($privateRoot, $configName);
            $pdo = Db::connect($c['db']);
        } catch (\Throwable $e) {
            error_log('[bootstrap] ' . $e->getMessage());
            // Not configured: /api/health still answers and says so; everything protected is 503.
            return new Api(static fn (string $t): array => throw HttpError::unauthorized(), null, PHP_VERSION, false, release: self::release());
        }
        $cache = rtrim($privateRoot, '/') . '/tmp';
        $jwt = new Jwt($c['clerk']['issuer'], $c['clerk']['authorized_parties'], $cache, self::fetch(...));
        $secret = $c['turnstile']['secret'];
        $turnstile = $secret !== ''
            ? static fn (string $token, ?string $ip): bool => Turnstile::verify($secret, $token, $ip, Turnstile::httpPost(...))
            : null;
        return new Api(
            static fn (string $t): array => $jwt->verify($t),
            $pdo,
            PHP_VERSION,
            true,
            $turnstile,
            $c['turnstile']['dev_bypass'],
            $c['office_email'],
            release: self::release(),
        );
    }

    /** Short id of the deployed commit, from the RELEASE file the deploy gate writes beside src/. */
    public static function release(?string $file = null): string
    {
        $text = @file_get_contents($file ?? dirname(__DIR__) . '/RELEASE');
        $id = is_string($text) ? trim($text) : '';
        return preg_match('/^[0-9a-f]{40}$/', $id) === 1 ? substr($id, 0, 12) : 'unknown';
    }

    private static function fetch(string $url): string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => false], 'ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            throw new \RuntimeException('JWKS fetch failed');
        }
        return $body;
    }
}
