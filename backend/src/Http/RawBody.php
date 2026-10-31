<?php

declare(strict_types=1);

namespace Vitalcare\Http;

/** A non-JSON response (a PDF or a CSV), always sent as a download with the given file name. */
final class RawBody
{
    public function __construct(
        public readonly string $content,
        public readonly string $contentType,
        public readonly string $filename,
    ) {
        if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $filename) !== 1) {
            throw new \InvalidArgumentException('Unsafe download file name');
        }
    }

    /** @return array<string,string> */
    public function headers(): array
    {
        return [
            'Content-Type' => $this->contentType,
            'Content-Disposition' => 'attachment; filename="' . $this->filename . '"',
            'Content-Length' => (string) strlen($this->content),
        ];
    }
}
