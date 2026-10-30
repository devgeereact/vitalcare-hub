<?php

declare(strict_types=1);

namespace Vitalcare;

/**
 * Routing and the response contract. handle() is pure of PHP superglobals so tests can call it
 * directly. Errors are always {"error":{"code","message"}} with no internals.
 */
final class Api
{
    /**
     * @param callable(string):?array<string,mixed> $verify returns verified claims for a bearer token, or throws HttpError
     * @param ?\PDO $pdo null when the database is not configured: protected routes then answer 503
     */
    public function __construct(
        private $verify,
        private readonly ?\PDO $pdo,
        private readonly string $phpVersion = PHP_VERSION,
        private readonly bool $authConfigured = true,
    ) {
    }

    /** @return array{0:int,1:array<string,mixed>} status and JSON body */
    public function handle(string $method, string $path, ?string $authorization): array
    {
        try {
            $path = rtrim($path, '/') ?: '/';
            return match (true) {
                $path === '/api/health' => $this->only('GET', $method, fn () => $this->health()),
                $path === '/api/me' => $this->only('GET', $method, fn () => $this->me($authorization)),
                default => throw HttpError::notFound(),
            };
        } catch (HttpError $e) {
            return [$e->status, ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]];
        } catch (\Throwable $e) {
            error_log('[api] ' . get_class($e) . ': ' . $e->getMessage());
            return [500, ['error' => ['code' => 'server_error', 'message' => 'Something went wrong. Please try again.']]];
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function only(string $allowed, string $method, callable $fn): array
    {
        if ($method !== $allowed) {
            throw new HttpError(405, 'method_not_allowed', 'Method not allowed');
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
        if ($this->pdo === null || !$this->authConfigured) {
            throw new HttpError(503, 'unavailable', 'The service is not available yet.');
        }
        if ($authorization === null || preg_match('/^Bearer ([A-Za-z0-9._-]{20,8192})$/i', $authorization, $m) !== 1) {
            throw HttpError::unauthorized();
        }
        $claims = ($this->verify)($m[1]);
        $user = (new SessionResolver($this->pdo))->resolve($claims ?? []);
        if ($user === null) {
            throw new HttpError(403, 'no_access', 'Your email address does not have access. Contact info@vitalcare.uk.');
        }
        return $user;
    }
}
