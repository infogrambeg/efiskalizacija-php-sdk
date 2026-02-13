<?php

declare(strict_types=1);

namespace Efiskalizacija\Http;

use Efiskalizacija\Exception\NetworkException;

final class CurlHttpClient implements HttpClientInterface
{
    private int $timeout;
    private int $connectTimeout;

    public function __construct(int $timeout = 30, int $connectTimeout = 10)
    {
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        string $body = '',
    ): Response {
        $ch = curl_init();

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = "{$name}: {$value}";
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_HEADER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
        }

        $rawResponse = curl_exec($ch);

        if ($rawResponse === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            throw new NetworkException(
                "cURL greska ({$errno}): {$error}",
                $errno
            );
        }

        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        /** @var string $rawResponse */
        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $responseBody = substr($rawResponse, $headerSize);

        $parsedHeaders = $this->parseHeaders($rawHeaders);

        return new Response($statusCode, $responseBody, $parsedHeaders);
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = explode("\r\n", trim($rawHeaders));

        foreach ($lines as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[trim($name)] = trim($value);
            }
        }

        return $headers;
    }
}
