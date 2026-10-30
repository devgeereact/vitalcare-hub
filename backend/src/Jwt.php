<?php
declare(strict_types=1);

// Adapted without behavioural change from siterelay/backend/src/Jwt.php (same owner), see docs/DECISIONS.md D-005.

namespace Vitalcare;

/**
 * Verifies Clerk session tokens: RS256 only, signature from the issuer's published JWKS,
 * then issuer, expiry, not-before, subject and authorised-party checks. Never trusts a token
 * (or its header) to choose the algorithm or the key source.
 */
final class Jwt
{
    private const LEEWAY = 5;

    /**
     * @param string[] $authorizedParties exact allowed values of the "azp" claim (origins)
     * @param callable(string):string $fetch returns the JWKS JSON for a URL (injected for tests)
     */
    public function __construct(
        private readonly string $issuer,
        private readonly array $authorizedParties,
        private readonly string $cacheDir,
        private $fetch,
        private readonly int $now = 0,
    ) {
        if (!str_starts_with($issuer, 'https://') || str_ends_with($issuer, '/')) {
            throw new \InvalidArgumentException('Issuer must be an https URL without trailing slash');
        }
    }

    /** @return array<string,mixed> verified claims */
    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || strlen($token) > 8192) {
            throw HttpError::unauthorized();
        }
        [$h, $p, $s] = $parts;
        $header = self::json($h);
        $claims = self::json($p);
        $sig = self::b64($s);
        if ($header === null || $claims === null || $sig === null || ($header['alg'] ?? null) !== 'RS256' || !is_string($header['kid'] ?? null)) {
            throw HttpError::unauthorized();
        }
        $pem = $this->pemForKid($header['kid']);
        if ($pem === null || openssl_verify($h . '.' . $p, $sig, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw HttpError::unauthorized();
        }
        $now = $this->now ?: time();
        $exp = $claims['exp'] ?? null;
        $nbf = $claims['nbf'] ?? null;
        $azp = $claims['azp'] ?? null;
        if (($claims['iss'] ?? null) !== $this->issuer
            || !is_int($exp) || $exp < $now - self::LEEWAY
            || ($nbf !== null && (!is_int($nbf) || $nbf > $now + self::LEEWAY))
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || !is_string($azp) || !in_array($azp, $this->authorizedParties, true)) {
            throw HttpError::unauthorized();
        }
        return $claims;
    }

    private function pemForKid(string $kid): ?string
    {
        $keys = $this->jwks(false);
        $jwk = self::find($keys, $kid);
        if ($jwk === null) {
            // Unknown kid: keys may have rotated. Refresh at most once a minute.
            $jwk = self::find($this->jwks(true), $kid);
        }
        return $jwk === null ? null : self::jwkToPem($jwk);
    }

    /** @return list<array<string,mixed>> */
    private function jwks(bool $forceRefresh): array
    {
        $file = $this->cacheDir . '/clerk-jwks-' . hash('sha256', $this->issuer) . '.json';
        $cached = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $age = is_array($cached) ? ($this->now ?: time()) - (int) ($cached['fetched_at'] ?? 0) : PHP_INT_MAX;
        $fresh = is_array($cached) && $age < 3600 && !$forceRefresh;
        if ($fresh) {
            return $cached['keys'];
        }
        if ($forceRefresh && is_array($cached) && $age < 60) {
            return $cached['keys'];
        }
        $failFile = $file . '.failed';
        if (is_file($failFile) && ($this->now ?: time()) - (int) @filemtime($failFile) < 30) {
            return is_array($cached) ? $cached['keys'] : []; // recent failure: do not wait on the network again yet
        }
        try {
            $doc = json_decode(($this->fetch)($this->issuer . '/.well-known/jwks.json'), true);
        } catch (\Throwable) {
            $doc = null;
        }
        if ((!is_array($doc) || !is_array($doc['keys'] ?? null)) && is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
            @touch($failFile);
        }
        if (!is_array($doc) || !is_array($doc['keys'] ?? null)) {
            return is_array($cached) ? $cached['keys'] : [];
        }
        if (is_dir($this->cacheDir) && is_writable($this->cacheDir)) {
            @file_put_contents($file, json_encode(['fetched_at' => $this->now ?: time(), 'keys' => $doc['keys']]), LOCK_EX);
        }
        return $doc['keys'];
    }

    /** @param list<array<string,mixed>> $keys */
    private static function find(array $keys, string $kid): ?array
    {
        foreach ($keys as $k) {
            if (is_array($k) && ($k['kid'] ?? null) === $kid && ($k['kty'] ?? null) === 'RSA' && is_string($k['n'] ?? null) && is_string($k['e'] ?? null)) {
                return $k;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $jwk */
    public static function jwkToPem(array $jwk): ?string
    {
        $n = self::b64((string) $jwk['n']);
        $e = self::b64((string) $jwk['e']);
        if ($n === null || $e === null || $n === '' || $e === '') {
            return null;
        }
        $rsaKey = self::seq(self::int($n) . self::int($e));
        $algo = hex2bin('300d06092a864886f70d0101010500');
        $spki = self::seq($algo . "\x03" . self::len(strlen($rsaKey) + 1) . "\x00" . $rsaKey);
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");
        return chr(0x80 | strlen($b)) . $b;
    }

    private static function seq(string $c): string { return "\x30" . self::len(strlen($c)) . $c; }

    private static function int(string $v): string
    {
        $v = ltrim($v, "\0");
        if ($v === '' || (ord($v[0]) & 0x80)) {
            $v = "\0" . $v;
        }
        return "\x02" . self::len(strlen($v)) . $v;
    }

    private static function b64(string $s): ?string
    {
        if ($s === '' || preg_match('/^[A-Za-z0-9_-]+$/', $s) !== 1) {
            return null;
        }
        $r = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        return $r === false ? null : $r;
    }

    /** @return array<string,mixed>|null */
    private static function json(string $b64): ?array
    {
        $raw = self::b64($b64);
        $d = $raw === null ? null : json_decode($raw, true);
        return is_array($d) ? $d : null;
    }
}
