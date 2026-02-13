<?php

declare(strict_types=1);

namespace Efiskalizacija\Http;

interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
    ): Response;
}
