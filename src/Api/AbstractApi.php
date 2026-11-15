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

    /**
     * Podskup `RETRYABLE_STATUS_CODES` koji server vraća PRE obrade zahteva
     * (ograničenje učestalosti): ponavlja se i zahtev koji nije bezbedan za
     * ponavljanje. Na 502, 503 i 504 se ne zna da li je server zahtev
     * obradio: 503 ne vraća samo aplikacija, nego i Apache (`mod_proxy_fcgi`,
     * text/html, bez `Retry-After`) kad je PHP zahtev već obradio, a odgovor
     * nije stigao do Apache-a (radnik prekinut, `request_terminate_timeout`).
     */
    private const RETRYABLE_BEFORE_PROCESSING = [429];

    /** Jedina ruta na kojoj server ponovljen POST prepoznaje (po `broj_racuna`). */
    private const IDEMPOTENT_POST_PATH = '/fiskalizacija';

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
    protected function post(string $endpoint, #[\SensitiveParameter] ?array $data = null): array
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
    private function requestWithRetry(string $method, string $path, #[\SensitiveParameter] string $body): array
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

    /**
     * Šalje zahtev i ponavlja ga posle prolazne greške samo kad je zahtev
     * bezbedan za ponavljanje ili kad je sigurno da ga server nije obradio
     * (ponavljanje nebezbednog zahteva bi moglo da napravi dupli fiskalni
     * račun ili drugi mejl):
     *
     * - zahtev bezbedan za ponavljanje ({@see self::isSafeToRepeat()}) se
     *   ponavlja na `NetworkException` i na 429, 502, 503, 504;
     * - ostali zahtevi se ponavljaju samo na 429 (server ga vraća pre obrade)
     *   i na `NetworkException` sa `wasRequestSent() === false` (zahtev
     *   sigurno nije poslat). Na 502, 503, 504 i mrežnu grešku posle koje se
     *   ne zna da li je zahtev stigao, izuzetak se baca odmah, bez pauze,
     *   uz WARNING (metoda, putanja bez query-ja, pokušaj, uzrok — bez tela,
     *   ključa i potpisa). Klijent tada proverava ishod pre novog slanja.
     *
     * Ni bezbedan zahtev nema potpunu garanciju (vidi
     * {@see self::isSafeToRepeat()}): kad se obrada na serveru prekine ili ne
     * uspe posle fiskalizacije, server račun nije upisao (ne vidi se ni u
     * listi računa ni u admin panelu), pa ponovljen zahtev sa istim brojem
     * izdaje drugi račun. Kad ishod ostane nepoznat i posle poslednjeg
     * pokušaja, sa brojem računa ili bez njega, klijent proverava račun pre
     * novog slanja; provera nalazi samo upisan račun, pa kad ga ne nađe,
     * klijent se pre novog slanja obraća podršci.
     */
    private function requestRawWithRetry(string $method, string $path, #[\SensitiveParameter] string $body): Response
    {
        // Granica važi i za Config koji nije prošao konstruktor (npr.
        // `unserialize()` objekta iz 1.1.0): najviše 1 + MAX_RETRIES zahteva.
        // Negativna vrednost je fail-closed: izuzetak pre prvog zahteva, sa
        // istom porukom koju daje konstruktor Config-a.
        $configuredRetries = $this->config->getMaxRetries();
        if ($configuredRetries < 0) {
            throw new \InvalidArgumentException(
                'maxRetries mora biti izmedju 0 i ' . Config::MAX_RETRIES . ", zadato {$configuredRetries}"
            );
        }
        $retries = min($configuredRetries, Config::MAX_RETRIES);
        $maxAttempts = $retries + 1;
        $lastException = null;
        $lastTimestamp = null;
        $safeToRepeat = self::isSafeToRepeat($method, $path, $body);

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

                $retryable = in_array($response->statusCode, self::RETRYABLE_STATUS_CODES, true);
                if (
                    $retryable
                    && !$safeToRepeat
                    && !in_array($response->statusCode, self::RETRYABLE_BEFORE_PROCESSING, true)
                ) {
                    $this->warnUnknownOutcome($method, $path, $attempt, ['status' => $response->statusCode]);
                    $this->throwForStatus($response);
                }

                if ($retryable && $attempt < $maxAttempts) {
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
                if (!$safeToRepeat && $e->wasRequestSent() !== false) {
                    $this->warnUnknownOutcome($method, $path, $attempt, ['exception_class' => $e::class]);

                    throw $e;
                }
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

    /**
     * Da li SDK sme sam da ponovi zahtev čiji ishod nije poznat: GET, ili
     * `POST /fiskalizacija` (putanja bez query-ja) čije telo nosi
     * `broj_racuna` — skalar koji nije bool, a posle `trim` nije prazan. To je
     * isto pravilo kao serverski `ClientReferenceGuard::normalize`: server
     * prepoznaje već upisan račun sa istim brojem i vraća ga umesto novog.
     * Ostaje prozor: server fiskalizuje račun pre nego što ga upiše, pa kad
     * se obrada prekine ili ne uspe posle fiskalizacije (pad servera, istek
     * odgovora Poreske uprave), račun postoji bez zapisa i ponovljen zahtev
     * sa istim brojem izdaje drugi račun. Sve ostalo (POST bez
     * broja, `/send-email`, `/test`, POST iz podklase na drugu rutu ili bez
     * broja računa) SDK ne ponavlja kad ishod nije poznat. Pravilo gleda samo
     * metodu, putanju i telo, pa se POST iz podklase na `/fiskalizacija` sa
     * brojem ponavlja kao `fiskalizuj()` sa brojem.
     */
    private static function isSafeToRepeat(string $method, string $path, #[\SensitiveParameter] string $body): bool
    {
        if ($method === 'GET') {
            return true;
        }
        if ($method !== 'POST' || explode('?', $path, 2)[0] !== self::IDEMPOTENT_POST_PATH) {
            return false;
        }

        $data = json_decode($body, true);
        if (!is_array($data) || !array_key_exists('broj_racuna', $data)) {
            return false;
        }
        $broj = $data['broj_racuna'];

        return is_scalar($broj) && !is_bool($broj) && trim((string) $broj) !== '';
    }

    /**
     * WARNING kad SDK ne ponavlja zahtev jer se ne zna da li ga je server
     * obradio. Kontekst je beli spisak: metoda, putanja bez query-ja, pokušaj
     * i uzrok (status ili klasa izuzetka) — nikad telo, ključ, potpis ni
     * poruka izuzetka.
     *
     * @param array{status?: int, exception_class?: class-string} $uzrok
     */
    private function warnUnknownOutcome(string $method, string $path, int $attempt, array $uzrok): void
    {
        $this->logger->warning('Ishod zahteva nije poznat, zahtev nije ponovljen', [
            'method' => $method,
            'path' => explode('?', $path, 2)[0],
            'attempt' => $attempt,
        ] + $uzrok);
    }

    /**
     * Pauza (ms) posle neuspelog pokušaja $attempt:
     * min(base × multiplier^(attempt−1), `Config::MAX_RETRY_DELAY_MS`).
     *
     * Računa se množenjem u petlji uz proveru PRE svakog množenja
     * (multiplier > granica / pauza), pa proizvod nikad ne prelazi granicu i
     * ne preliva se u float — za bilo koje int vrednosti, i one koje `Config`
     * ne dozvoljava (npr. objekat iz `unserialize()`): negativna baza daje 0,
     * multiplier < 1 drži pauzu na bazi.
     */
    private function calculateDelay(int $attempt): int
    {
        $max = Config::MAX_RETRY_DELAY_MS;
        $delay = min(max($this->config->getRetryBaseDelayMs(), 0), $max);
        $multiplier = $this->config->getRetryMultiplier();

        if ($delay === 0 || $multiplier <= 1) {
            return $delay;
        }

        for ($i = 1; $i < $attempt; $i++) {
            if ($multiplier > intdiv($max, $delay)) {
                return $max;
            }
            $delay *= $multiplier;
        }

        return $delay;
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
     * Granica: `Config` dozvoljava `maxRetries` najviše `Config::MAX_RETRIES`
     * (10), a `requestRawWithRetry()` broj ponovljenih pokušaja ograničava na
     * isti opseg i za Config koji nije prošao konstruktor (`unserialize()`),
     * pa je pomak ispred sata uvek najviše 10 s (uz skok sata unazad J + 10),
     * daleko unutar tolerancije servera.
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
