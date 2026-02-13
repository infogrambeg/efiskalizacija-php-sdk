<?php

declare(strict_types=1);

namespace Efiskalizacija\Api;

use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\Exception\AuthenticationException;
use Efiskalizacija\Exception\EfiskalizacijaException;
use Efiskalizacija\Exception\ForbiddenException;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Exception\RateLimitException;
use Efiskalizacija\Exception\ServerException;
use Efiskalizacija\Exception\ValidationException;
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

abstract class AbstractApi
{
    private const API_BASE_PATH = '/api/multitenant.php';

    private const RETRYABLE_STATUS_CODES = [429, 502, 503, 504];

    protected Config $config;
    private HttpClientInterface $httpClient;
    private HmacSigner $signer;
    private LoggerInterface $logger;

    public function __construct(
        Config $config,
        HttpClientInterface $httpClient,
        HmacSigner $signer,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->signer = $signer;
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed>|null $data POST body
     * @param array<string, string> $query Query parametri
     * @return array<string, mixed> Parsed API response
     */
    protected function post(string $endpoint, ?array $data = null): array
    {
        $body = $data !== null ? json_encode($data, JSON_UNESCAPED_UNICODE) : '';

        return $this->requestWithRetry('POST', $endpoint, $body ?: '');
    }

    /**
     * @param array<string, string> $query Query parametri
     * @return array<string, mixed> Parsed API response
     */
    protected function get(string $endpoint, array $query = []): array
    {
        $path = $endpoint;
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return $this->requestWithRetry('GET', $path, '');
    }

    /**
     * Sirovi GET za binarni sadrzaj (PDF).
     *
     * @param array<string, string> $query
     */
    protected function getRaw(string $endpoint, array $query = []): Response
    {
        $path = $endpoint;
        if (!empty($query)) {
            $path .= '?' . http_build_query($query);
        }

        return $this->requestRawWithRetry('GET', $path, '');
    }

    /**
     * @return array<string, mixed>
     */
    private function requestWithRetry(string $method, string $path, string $body): array
    {
        $response = $this->requestRawWithRetry($method, $path, $body);
        $json = $response->json();

        if ($json === null) {
            throw new EfiskalizacijaException(
                'Neispravan JSON odgovor od API-ja',
                $response->statusCode,
                responseBody: $response->body,
            );
        }

        return $json;
    }

    private function requestRawWithRetry(string $method, string $path, string $body): Response
    {
        $maxAttempts = $this->config->getMaxRetries() + 1;
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $fullPath = self::API_BASE_PATH . $path;
                $url = $this->config->getBaseUrl() . $fullPath;

                $authHeaders = $this->signer->getAuthHeaders(
                    $this->config->getApiKey(),
                    $method,
                    $fullPath,
                    $body,
                );

                $headers = array_merge($authHeaders, [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ]);

                $this->logger->debug('API zahtev', [
                    'method' => $method,
                    'url' => $url,
                    'attempt' => $attempt,
                ]);

                $response = $this->httpClient->request($method, $url, $headers, $body);

                if ($response->isSuccess()) {
                    return $response;
                }

                if (
                    in_array($response->statusCode, self::RETRYABLE_STATUS_CODES, true)
                    && $attempt < $maxAttempts
                ) {
                    $delayMs = $this->calculateDelay($attempt);
                    $this->logger->warning('Retryable greska, ponovni pokusaj', [
                        'status' => $response->statusCode,
                        'attempt' => $attempt,
                        'delay_ms' => $delayMs,
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }

                $this->throwForStatus($response);
            } catch (NetworkException $e) {
                $lastException = $e;
                if ($attempt < $maxAttempts) {
                    $delayMs = $this->calculateDelay($attempt);
                    $this->logger->warning('Mrezna greska, ponovni pokusaj', [
                        'error' => $e->getMessage(),
                        'attempt' => $attempt,
                        'delay_ms' => $delayMs,
                    ]);
                    usleep($delayMs * 1000);
                    continue;
                }
            }
        }

        if ($lastException !== null) {
            throw $lastException;
        }

        // @codeCoverageIgnoreStart
        throw new EfiskalizacijaException('Iscrpljeni svi pokusaji');
        // @codeCoverageIgnoreEnd
    }

    private function calculateDelay(int $attempt): int
    {
        return $this->config->getRetryBaseDelayMs()
            * ($this->config->getRetryMultiplier() ** ($attempt - 1));
    }

    /**
     * @throws EfiskalizacijaException
     * @return never
     */
    private function throwForStatus(Response $response): never
    {
        $json = $response->json();
        $message = $json['error'] ?? 'Nepoznata greska';

        match (true) {
            $response->statusCode === 401 => throw new AuthenticationException(
                $message, 401, responseBody: $response->body,
            ),
            $response->statusCode === 403 => throw new ForbiddenException(
                $message, 403, responseBody: $response->body,
            ),
            $response->statusCode === 429 => throw new RateLimitException(
                $message,
                $this->parseRetryAfter($response),
                429,
                responseBody: $response->body,
            ),
            in_array($response->statusCode, [400, 422], true) => throw new ValidationException(
                $message,
                $json['errors'] ?? [],
                $response->statusCode,
                responseBody: $response->body,
            ),
            $response->statusCode >= 500 => throw new ServerException(
                $message, $response->statusCode, responseBody: $response->body,
            ),
            default => throw new EfiskalizacijaException(
                $message, $response->statusCode, responseBody: $response->body,
            ),
        };
    }

    private function parseRetryAfter(Response $response): ?int
    {
        $header = $response->getHeader('Retry-After');
        if ($header !== null && ctype_digit($header)) {
            return (int) $header;
        }
        return null;
    }
}
