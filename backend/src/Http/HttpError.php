<?php

declare(strict_types=1);

namespace Vitalcare\Http;

/** An error that is safe to show to the client: stable code, short message, optional field messages. No internals. */
final class HttpError extends \RuntimeException
{
    /**
     * @param array<string,string> $fields per-field messages for form validation
     * @param array<string,string> $headers extra response headers (for example Retry-After)
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message = '',
        public readonly array $fields = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message !== '' ? $message : $errorCode, $status);
    }

    public static function notFound(): self { return new self(404, 'not_found', 'Not found'); }
    public static function forbidden(string $why = 'You do not have permission to do that'): self { return new self(403, 'forbidden', $why); }
    public static function unauthorized(string $why = 'Sign in required'): self { return new self(401, 'unauthorized', $why); }
    public static function conflict(string $code, string $message): self { return new self(409, $code, $message); }

    /** @param array<string,string> $fields */
    public static function invalid(string $message, array $fields = []): self
    {
        return new self(422, 'invalid_input', $message, $fields);
    }

    public static function tooMany(int $retryAfterSeconds): self
    {
        $s = max(1, $retryAfterSeconds);
        return new self(429, 'rate_limited', 'Too many requests. Please try again later.', [], ['Retry-After' => (string) $s]);
    }
}
