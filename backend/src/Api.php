<?php

declare(strict_types=1);

namespace Vitalcare;

/**
 * Routing and the response contract. handle() is pure of PHP superglobals so tests can call it
 * directly. Errors are always {"error":{"code","message"}} (plus "fields" for form validation),
 * with no internals.
 */
final class Api
{
    private const MAX_BODY = 16384;

    /**
     * @param callable(string):?array<string,mixed> $verify returns verified claims for a bearer token, or throws HttpError
     * @param ?\PDO $pdo null when the database is not configured: protected routes then answer 503
     * @param (callable(string,?string):bool)|null $turnstile verifies a Turnstile token (token, client ip). Null means not configured
     * @param bool $turnstileBypass local development only: skip the Turnstile check. Never set in production config
     * @param (\Closure():\DateTimeImmutable)|null $clock injected by tests
     */
    public function __construct(
        private $verify,
        private readonly ?\PDO $pdo,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly bool $authConfigured = true,
        private $turnstile = null,
        private readonly bool $turnstileBypass = false,
        private readonly string $officeEmail = 'info@vitalcare.uk',
        private readonly ?\Closure $clock = null,
    ) {
    }

    /** @return array{0:int,1:array<string,mixed>,2?:array<string,string>} status, JSON body, optional extra headers */
    public function handle(string $method, string $path, ?string $authorization, ?string $body = null, string $clientIp = ''): array
    {
        try {
            $path = rtrim($path, '/') ?: '/';
            return match (true) {
                $path === '/api/health' => $this->only('GET', $method, fn () => $this->health()),
                $path === '/api/me' => $this->only('GET', $method, fn () => $this->me($authorization)),
                preg_match('#^/api/verify/([^/]{1,60})$#', $path, $m) === 1 => $this->only('GET', $method, fn () => $this->verifyCertificate($m[1], $clientIp)),
                $path === '/api/contact' => $this->only('POST', $method, fn () => $this->contact($body, $clientIp)),
                default => throw HttpError::notFound(),
            };
        } catch (HttpError $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->fields !== []) {
                $error['fields'] = $e->fields;
            }
            return [$e->status, ['error' => $error], $e->headers];
        } catch (\Throwable $e) {
            error_log('[api] ' . get_class($e) . ': ' . $e->getMessage());
            return [500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong. Please try again.']]];
        }
    }

    /** @return array{0:int,1:array<string,mixed>,2?:array<string,string>} */
    private function only(string $allowed, string $method, callable $fn): array
    {
        if ($method !== $allowed) {
            throw new HttpError(405, 'method_not_allowed', 'Method not allowed', [], ['Allow' => $allowed]);
        }
        return $fn();
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function health(): array
    {
        return [200, ['status' => 'ok', 'php' => $this->phpVersion, 'authConfigured' => $this->authConfigured && $this->pdo !== null]];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function me(?string $authorization): array
    {
        $user = $this->authenticate($authorization);
        return [200, ['user' => [
            'id' => $user->id,
            'email' => $user->email,
            'displayName' => $user->displayName,
            'role' => $user->role,
            'organisationId' => $user->organisationId,
        ]]];
    }

    /** 401 for a missing or invalid token; 403 for a valid token that has no row here. */
    private function authenticate(?string $authorization): User
    {
        $pdo = $this->database();
        if (!$this->authConfigured) {
            throw $this->unavailable();
        }
        if ($authorization === null || preg_match('/^Bearer ([A-Za-z0-9._-]{20,8192})$/i', $authorization, $m) !== 1) {
            throw HttpError::unauthorized();
        }
        $claims = ($this->verify)($m[1]);
        $user = (new SessionResolver($pdo))->resolve($claims ?? []);
        if ($user === null) {
            throw new HttpError(403, 'no_access', 'Your email address does not have access. Contact info@vitalcare.uk.');
        }
        return $user;
    }

    /** Public. Same answer shape for every kind of "not issued". 30 checks per 5 minutes per caller. */
    private function verifyCertificate(string $code, string $clientIp): array
    {
        $pdo = $this->database();
        (new RateLimiter($pdo, $this->clock !== null ? fn (): int => ($this->clock)()->getTimestamp() : null))
            ->hit('verify', $clientIp !== '' ? $clientIp : 'unknown', 30, 300);
        $now = $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return [200, (new CertificateVerifier($pdo))->check(urldecode($code), $now), ['Cache-Control' => 'no-store']];
    }

    /** Public. 5 submissions per hour per caller; Turnstile required unless explicitly bypassed for local development. */
    private function contact(?string $body, string $clientIp): array
    {
        $pdo = $this->database();
        if ($this->turnstile === null && !$this->turnstileBypass) {
            throw $this->unavailable();
        }
        (new RateLimiter($pdo, $this->clock !== null ? fn (): int => ($this->clock)()->getTimestamp() : null))
            ->hit('contact', $clientIp !== '' ? $clientIp : 'unknown', 5, 3600);

        if ($body === null || strlen($body) > self::MAX_BODY) {
            throw new HttpError(413, 'payload_too_large', 'That message is too large.');
        }
        $in = json_decode($body, true);
        if (!is_array($in) || array_is_list($in)) {
            throw HttpError::invalid('Send the form as a JSON object.');
        }

        // Honeypot: real people never see this field. Pretend success and store nothing.
        if (isset($in['website']) && $in['website'] !== '') {
            return [201, ['received' => true]];
        }

        $valid = ContactService::validate($in);

        if (!$this->turnstileBypass) {
            $token = is_string($in['turnstileToken'] ?? null) ? $in['turnstileToken'] : '';
            if ($this->turnstile === null || ($this->turnstile)($token, $clientIp !== '' ? $clientIp : null) !== true) {
                throw new HttpError(403, 'verification_failed', 'We could not check that you are a person. Please try again.');
            }
        }

        (new ContactService($pdo, $this->officeEmail))->store($valid, $clientIp !== '' ? hash('sha256', $clientIp) : null);
        return [201, ['received' => true]];
    }

    private function database(): \PDO
    {
        if ($this->pdo === null) {
            throw $this->unavailable();
        }
        return $this->pdo;
    }

    private function unavailable(): HttpError
    {
        return new HttpError(503, 'unavailable', 'The service is not available yet.');
    }
}
