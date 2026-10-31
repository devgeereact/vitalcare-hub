<?php

declare(strict_types=1);

namespace Vitalcare\Cms;

use Vitalcare\Auth\SessionResolver;
use Vitalcare\Http\HttpError;

/**
 * Validates one JSON request body. Each value() call normalises a field or records a plain-English
 * error for it; check() throws one 422 with every field message. Unknown keys are ignored, never stored.
 */
final class Input
{
    public const MAX_BODY = 65536;

    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public static function json(?string $body): self
    {
        if ($body === null || trim($body) === '') {
            throw HttpError::invalid('Send the form as a JSON object.');
        }
        if (strlen($body) > self::MAX_BODY) {
            throw new HttpError(413, 'payload_too_large', 'That is too large to save.');
        }
        $d = json_decode($body, true);
        if (!is_array($d) || ($d !== [] && array_is_list($d))) {
            throw HttpError::invalid('Send the form as a JSON object.');
        }
        return new self($d);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function error(string $key, string $message): void
    {
        $this->errors[$key] ??= $message;
    }

    public function check(): void
    {
        if ($this->errors !== []) {
            throw HttpError::invalid('Check the highlighted fields and try again.', $this->errors);
        }
    }

    /**
     * Types: text, longtext, email, phone, url, slug, bool, int, decimal, enum, date, datetime (Europe/London
     * local YYYY-MM-DDTHH:MM, returned as UTC Y-m-d H:i:s), uuid.
     * Options: required, max, min, values (enum), blank (store '' instead of null for an empty text).
     *
     * @param array<string,mixed> $o
     */
    public function value(string $key, string $type, array $o = []): mixed
    {
        $raw = $this->data[$key] ?? null;
        $required = ($o['required'] ?? false) === true;
        $empty = $raw === null || (is_string($raw) && trim($raw) === '');
        if ($empty) {
            if ($required) {
                $this->error($key, 'This is required.');
            }
            return in_array($type, ['text', 'longtext'], true) && ($o['blank'] ?? false) ? '' : null;
        }

        switch ($type) {
            case 'text':
            case 'longtext':
                if (!is_string($raw)) {
                    return $this->bad($key, 'Enter text.');
                }
                $v = trim(str_replace("\r\n", "\n", $raw));
                $control = $type === 'text' ? '/[\x00-\x1f\x7f]/' : '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/';
                if (!mb_check_encoding($v, 'UTF-8') || preg_match($control, $v) === 1) {
                    return $this->bad($key, 'Remove the unusual characters.');
                }
                $max = (int) ($o['max'] ?? ($type === 'text' ? 190 : 5000));
                if (mb_strlen($v) > $max) {
                    return $this->bad($key, "Keep this under {$max} characters.");
                }
                $min = (int) ($o['min'] ?? 0);
                if (mb_strlen($v) < $min) {
                    return $this->bad($key, "Write at least {$min} characters.");
                }
                return $v;
            case 'email':
                $v = SessionResolver::normaliseEmail($raw);
                return $v ?? $this->bad($key, 'Enter a valid email address.');
            case 'phone':
                if (!is_string($raw) || preg_match('/^[0-9 +()\-]{6,30}$/', trim($raw)) !== 1) {
                    return $this->bad($key, 'Use digits, spaces, plus, brackets or hyphens.');
                }
                return trim($raw);
            case 'url':
                $v = is_string($raw) ? trim($raw) : '';
                if (strlen($v) > 500 || !str_starts_with($v, 'https://') || filter_var($v, FILTER_VALIDATE_URL) === false) {
                    return $this->bad($key, 'Enter a full link starting with https://');
                }
                return $v;
            case 'slug':
                if (!is_string($raw) || strlen($raw) > 80 || preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $raw) !== 1) {
                    return $this->bad($key, 'Use lowercase letters, numbers and single hyphens, for example basic-life-support.');
                }
                return $raw;
            case 'bool':
                return is_bool($raw) ? $raw : $this->bad($key, 'Choose yes or no.');
            case 'int':
                if (!is_int($raw) && !(is_string($raw) && preg_match('/^-?\d{1,9}$/', $raw) === 1)) {
                    return $this->bad($key, 'Enter a whole number.');
                }
                $v = (int) $raw;
                if ((isset($o['min']) && $v < $o['min']) || (isset($o['max']) && $v > $o['max'])) {
                    return $this->bad($key, 'Enter a number from ' . ($o['min'] ?? 0) . ' to ' . ($o['max'] ?? 0) . '.');
                }
                return $v;
            case 'decimal':
                if (!is_int($raw) && !is_float($raw) && !(is_string($raw) && is_numeric($raw))) {
                    return $this->bad($key, 'Enter a number.');
                }
                $v = round((float) $raw, 2);
                if ($v <= 0 || $v > 999.99) {
                    return $this->bad($key, 'Enter a number above 0 and below 1,000.');
                }
                return $v;
            case 'enum':
                $values = $o['values'] ?? [];
                return is_string($raw) && in_array($raw, $values, true) ? $raw : $this->bad($key, 'Choose one of the options.');
            case 'date':
                if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return $this->bad($key, 'Enter a date.');
                }
                return $raw;
            case 'datetime':
                $v = is_string($raw) ? LondonTime::toUtc($raw) : null;
                return $v ?? $this->bad($key, 'Enter a date and time.');
            case 'uuid':
                if (!is_string($raw) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $raw) !== 1) {
                    return $this->bad($key, 'Choose one from the list.');
                }
                return $raw;
        }
        throw new \LogicException("Unknown input type $type");
    }

    private function bad(string $key, string $message): mixed
    {
        $this->error($key, $message);
        return null;
    }
}
