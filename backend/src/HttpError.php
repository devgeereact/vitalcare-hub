<?php
declare(strict_types=1);

namespace Vitalcare;

/** An error that is safe to show to the client: stable code, short message, no internals. */
final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $errorCode, $status);
    }

    public static function notFound(): self { return new self(404, 'not_found', 'Not found'); }
    public static function forbidden(string $why = 'You do not have permission to do that'): self { return new self(403, 'forbidden', $why); }
    public static function unauthorized(string $why = 'Sign in required'): self { return new self(401, 'unauthorized', $why); }
    public static function invalid(string $message): self { return new self(422, 'invalid_input', $message); }
    public static function conflict(string $code, string $message): self { return new self(409, $code, $message); }
}
