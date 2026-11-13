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

    /**
     * Zamena sata; null = `time()`. Podrazumevana vrednost se NE
     * materijalizuje kao Closure, pa objekat ostaje serijalizabilan kao u
     * 1.0.2, a objekat deserijalizovan iz 1.0.2 (bez ovog svojstva) radi.
     *
     * @var (\Closure(): int)|null
     */
    private ?\Closure $clock = null;

    /**
     * Zamena pauze u mikrosekundama; null = `usleep()`. Vidi `$clock`.
     *
     * @var (\Closure(int): void)|null
     */
    private ?\Closure $sleep = null;

    /**
     * @param (\Closure(): int)|null $clock  Unix vreme za `X-Timestamp`
     *                                       (null = `time()`)
     * @param (\Closure(int): void)|null $sleep Pauza u mikrosekundama
     *                                       (null = `usleep()`)
     *
     * `$clock` i `$sleep` su tačke zamene (npr. u testovima). Namerno su
     * parametri konstruktora a ne metode: ne ulaze u imenski prostor metoda
     * podklasa, pa podklasa koja već ima npr. sopstveni `pause()` ostaje
     * ispravna i SDK njene metode ne poziva. Zadat Closure čini objekat
     * neserijalizabilnim (ograničenje PHP-a).
     */
    public function __construct(
        Config $config,
        HttpClientInterface $httpClient,
        HmacSigner $signer,
        ?LoggerInterface $logger = null,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient;
        $this->signer = $signer;
        $this->logger = $logger ?? new NullLogger();
        $this->clock = $clock;
        $this->sleep = $sleep;
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
        return $this->requestWithRetry('GET', $endpoint . self::queryString($query), '');
    }

    /**
     * Sirovi GET za binarni sadrzaj (PDF).
     *
     * @param array<string, string> $query
     */
    protected function getRaw(string $endpoint, array $query = []): Response
    {
        return $this->requestRawWithRetry('GET', $endpoint . self::queryString($query), '');
    }

    /**
     * `?` + query kodiran po RFC 3986 (razmak `%20`, `~` nekodiran), ili ''
     * kad je query prazan (i kad `http_build_query` ne da ništa, npr. same
     * `null` vrednosti).
     *
     * RFC 3986 oblik je fiksna tačka za česte HTTP klijente (Guzzle, Symfony
     * HttpClient, WP Requests): šalju ga neizmenjenog, pa je potpisan cilj
     * jednak poslatom. RFC 1738 oblik (`%7E`) Symfony i WP Requests
     * prepakuju u `~`, a prazan `?` Guzzle i Symfony odbacuju.
     *
     * @param array<string, mixed> $query
     */
    private static function queryString(array $query): string
    {
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        return $qs === '' ? '' : '?' . $qs;
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
        $lastTimestamp = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $target = self::API_BASE_PATH . $path;
                $url = $this->config->getBaseUrl() . $target;
                $lastTimestamp = $this->nextTimestamp($lastTimestamp);

                // Potpisuje se cilj sa query-jem TAČNO kako se šalje, bez
                // putanje baznog URL-a: server je uvek na korenu hosta
                // (REQUEST_URI = /api/multitenant.php/...). Bazni URL sa
                // putanjom radi samo iza gateway-a koji skida prefiks — i tamo
                // server vidi cilj bez prefiksa.
                $authHeaders = $this->signer->getAuthHeadersV2(
                    $this->config->getApiKey(),
                    $method,
                    $target,
                    $body,
                    $lastTimestamp,
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
                    $this->sleepFor($delayMs * 1000);
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
                    $this->sleepFor($delayMs * 1000);
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
     * `X-Timestamp` za sledeći pokušaj: max(tekući sat, prethodni + 1).
     * Strogo raste u okviru poziva, pa dva pokušaja nikad nemaju isti potpis,
     * i nikad se ne čeka.
     *
     * Prvi pokušaj nosi tekući sat. Kad su pokušaji brži od 1 s (ili sat
     * stoji), timestamp ide ISPRED tekućeg sata najviše za k sekundi, gde je
     * k broj ponovljenih pokušaja do tog trenutka (najviše `maxRetries`); kad
     * sat tokom poziva skoči unazad za J sekundi, za J + k. Server prihvata
     * timestamp ±300 s od SVOG sata.
     *
     * Granica: `maxRetries` nije ograničen, a pomak raste do k = `maxRetries`.
     * Uz razmak 0 (pokušaji u istoj sekundi) timestamp izlazi iz tolerancije
     * servera od ~302. pokušaja, pa `maxRetries` treba da bude daleko ispod
     * 300 (podrazumevano 3).
     */
    private function nextTimestamp(?int $previous): int
    {
        $now = $this->clockNow();

        return $previous === null ? $now : max($now, $previous + 1);
    }

    /**
     * Unix vreme za `X-Timestamp`: `$clock` konstruktora, inače `time()`.
     * Posle `unserialize()` i u podklasi bez roditeljskog konstruktora
     * `$clock` je null (podrazumevana vrednost svojstva); `?? null` pokriva
     * i neinicijalizovano svojstvo, koje nastaje samo eksplicitnim `unset()`.
     */
    private function clockNow(): int
    {
        $clock = $this->clock ?? null;

        return $clock !== null ? $clock() : time();
    }

    /** Pauza u mikrosekundama: `$sleep` konstruktora, inače `usleep()`. */
    private function sleepFor(int $microseconds): void
    {
        $sleep = $this->sleep ?? null;
        if ($sleep !== null) {
            $sleep($microseconds);

            return;
        }
        if ($microseconds > 0) {
            usleep($microseconds);
        }
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
