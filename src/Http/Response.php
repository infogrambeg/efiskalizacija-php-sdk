<?php

declare(strict_types=1);

namespace Efiskalizacija\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }

    public function getHeader(string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $lower) {
                return $value;
            }
        }
        return null;
    }
}
