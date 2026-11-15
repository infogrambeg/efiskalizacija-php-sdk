<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Api\StatusApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\EfiskalizacijaClient;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Exception\AuthenticationException;
use Efiskalizacija\Exception\ForbiddenException;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Exception\RateLimitException;
use Efiskalizacija\Exception\ServerException;
use Efiskalizacija\Exception\ValidationException;
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;
use PHPUnit\Framework\TestCase;

final class AbstractApiTest extends TestCase
{
    private Config $config;
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 2);
        $this->signer = new HmacSigner($this->config->getApiSecret());
    }

    private function makeApi(HttpClientInterface $httpClient): FiskalizacijaApi
    {
        return new FiskalizacijaApi($this->config, $httpClient, $this->signer);
    }

    private function makeStatusApi(HttpClientInterface $httpClient): StatusApi
    {
        return new StatusApi($this->config, $httpClient, $this->signer);
    }

    private function makeInvoice(): Invoice
    {
        return Invoice::create()
            ->setPaymentType(PaymentType::Kartica)
            ->addItem(new InvoiceItem(naziv: 'Test', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20));
    }

    /**
     * Račun sa `broj_racuna`: od 1.2.0 jedini POST koji SDK ponavlja posle
     * 502/503/504 i mrežne greške nepoznatog ishoda (server ga prepoznaje po
     * broju). POST bez broja se tada ne ponavlja — vidi
     * {@see PonavljanjeNeizvesnogIshodaTest}.
     */
    private function makeInvoiceSaBrojem(): Invoice
    {
        return $this->makeInvoice()->setInvoiceNumber('SHOP-2026-001');
    }

    // --- Uspesni odgovori ---

    public function testSuccessfulPost(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, json_encode([
                'success' => true,
                'data' => [
                    'rezultat' => [
                        'pfr_broj' => 'TEST-1234',
                        'qr_code' => 'https://qr.test',
                        'racun_id' => 42,
                    ],
                ],
            ])));

        $api = $this->makeApi($mock);
        $result = $api->fiskalizuj($this->makeInvoice());

        $this->assertSame('TEST-1234', $result->pfrBroj);
        $this->assertSame('https://qr.test', $result->qrCode);
        $this->assertSame(42, $result->racunId);
    }

    public function testSuccessfulGet(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(200, json_encode([
                'success' => true,
                'data' => [
                    'tenant' => ['naziv' => 'Firma', 'pib' => '123456789', 'environment' => 'test',
                        'current_month_invoices' => 10, 'max_invoices_per_month' => 1000],
                    'statistics' => ['total_invoices' => 50, 'this_month' => 10, 'success_rate' => 98.5],
                    'system' => ['version' => '1.2.0'],
                ],
            ])));

        $api = $this->makeStatusApi($mock);
        $result = $api->fetch();

        $this->assertSame('Firma', $result->naziv);
        $this->assertSame(50, $result->totalInvoices);
    }

    // --- HTTP error kodovi → tipovane exception klase ---

    public function testThrows401AsAuthenticationException(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(401, json_encode([
                'success' => false,
                'error' => 'Neispravan API key',
            ])));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Neispravan API key');
        $this->expectExceptionCode(401);

        $this->makeApi($mock)->fiskalizuj($this->makeInvoice());
    }

    public function testThrows403AsForbiddenException(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(403, json_encode([
                'success' => false,
                'error' => 'Nalog deaktiviran',
            ])));

        $this->expectException(ForbiddenException::class);
        $this->expectExceptionCode(403);

        $this->makeApi($mock)->fiskalizuj($this->makeInvoice());
    }

    public function testThrows400AsValidationException(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(400, json_encode([
                'success' => false,
                'error' => 'Stavke su obavezne',
            ])));

        $this->expectException(ValidationException::class);
        $this->expectExceptionCode(400);

        $this->makeApi($mock)->fiskalizuj($this->makeInvoice());
    }

    public function testThrows422AsValidationException(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(422, json_encode([
                'success' => false,
                'error' => 'Validacija neuspesna',
                'errors' => ['PDV stopa nije validna'],
            ])));

        try {
            $this->makeApi($mock)->fiskalizuj($this->makeInvoice());
            $this->fail('Trebalo je da baci ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getCode());
            $this->assertSame(['PDV stopa nije validna'], $e->getErrors());
        }
    }

    public function testThrows500AsServerException(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(500, json_encode([
                'success' => false,
                'error' => 'Internal Server Error',
            ])));

        $this->expectException(ServerException::class);
        $this->expectExceptionCode(500);

        $this->makeApi($mock)->fiskalizuj($this->makeInvoice());
    }

    // --- Retry logika ---

    public function testRetryOn429ThenSuccess(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                new Response(429, json_encode(['success' => false, 'error' => 'Rate limit'])),
                new Response(200, json_encode([
                    'success' => true,
                    'data' => ['rezultat' => ['pfr_broj' => 'RETRY-OK', 'racun_id' => 1]],
                ])),
            );

        // Koristimo config sa kraćim delay-em za test
        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $result = $api->fiskalizuj($this->makeInvoice());

        $this->assertSame('RETRY-OK', $result->pfrBroj);
    }

    public function testRetryOn502ThenSuccess(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                new Response(502, json_encode(['success' => false, 'error' => 'Bad Gateway'])),
                new Response(200, json_encode([
                    'success' => true,
                    'data' => ['rezultat' => ['pfr_broj' => 'GW-OK', 'racun_id' => 2]],
                ])),
            );

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $result = $api->fiskalizuj($this->makeInvoiceSaBrojem());

        $this->assertSame('GW-OK', $result->pfrBroj);
    }

    public function testRetryOn503ThenSuccess(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls(
                new Response(503, json_encode(['success' => false, 'error' => 'Unavailable'])),
                new Response(200, json_encode([
                    'success' => true,
                    'data' => ['rezultat' => ['pfr_broj' => 'SVC-OK', 'racun_id' => 3]],
                ])),
            );

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $result = $api->fiskalizuj($this->makeInvoiceSaBrojem());

        $this->assertSame('SVC-OK', $result->pfrBroj);
    }

    public function testRetryExhaustedThrowsLastError(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(3)) // 1 initial + 2 retries
            ->method('request')
            ->willReturn(new Response(503, json_encode([
                'success' => false,
                'error' => 'Service Unavailable',
            ])));

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $this->expectException(ServerException::class);
        $this->expectExceptionMessage('Service Unavailable');

        $api->fiskalizuj($this->makeInvoiceSaBrojem());
    }

    public function testNoRetryOn400(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->once()) // Samo 1 poziv, bez retry-a
            ->method('request')
            ->willReturn(new Response(400, json_encode([
                'success' => false,
                'error' => 'Neispravni podaci',
            ])));

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $this->expectException(ValidationException::class);

        $api->fiskalizuj($this->makeInvoice());
    }

    public function testNoRetryOn401(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->once())
            ->method('request')
            ->willReturn(new Response(401, json_encode([
                'success' => false,
                'error' => 'Unauthorized',
            ])));

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $this->expectException(AuthenticationException::class);

        $api->fiskalizuj($this->makeInvoice());
    }

    // --- Network greske ---

    public function testRetryOnNetworkErrorThenSuccess(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(2))
            ->method('request')
            ->willReturnCallback(function () {
                static $call = 0;
                $call++;
                if ($call === 1) {
                    throw new NetworkException('Connection timed out', 28);
                }
                return new Response(200, json_encode([
                    'success' => true,
                    'data' => ['rezultat' => ['pfr_broj' => 'NET-OK', 'racun_id' => 4]],
                ]));
            });

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $result = $api->fiskalizuj($this->makeInvoiceSaBrojem());

        $this->assertSame('NET-OK', $result->pfrBroj);
    }

    public function testNetworkErrorExhaustedThrows(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->exactly(3))
            ->method('request')
            ->willThrowException(new NetworkException('DNS resolution failed', 6));

        $config = new Config('efisk_1_test', 'secret', maxRetries: 2, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        $this->expectException(NetworkException::class);
        $this->expectExceptionMessage('DNS resolution failed');

        $api->fiskalizuj($this->makeInvoiceSaBrojem());
    }

    // --- Rate limit sa Retry-After ---

    public function testRateLimitExceptionContainsRetryAfter(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(
                429,
                json_encode(['success' => false, 'error' => 'Rate limit dostignut']),
                ['Retry-After' => '30'],
            ));

        $config = new Config('efisk_1_test', 'secret', maxRetries: 0, retryBaseDelayMs: 1);
        $api = new FiskalizacijaApi($config, $mock, $this->signer);

        try {
            $api->fiskalizuj($this->makeInvoice());
            $this->fail('Trebalo je da baci RateLimitException');
        } catch (RateLimitException $e) {
            $this->assertSame(30, $e->getRetryAfter());
            $this->assertSame(429, $e->getCode());
        }
    }

    // --- Auth headeri ---

    public function testRequestIncludesAuthHeaders(): void
    {
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->expects($this->once())
            ->method('request')
            ->with(
                $this->identicalTo('GET'),
                $this->stringContains('/api/multitenant.php/status'),
                $this->callback(function (array $headers): bool {
                    return isset($headers['X-API-Key'])
                        && isset($headers['X-Timestamp'])
                        && isset($headers['X-Signature'])
                        && isset($headers['Content-Type'])
                        && $headers['X-API-Key'] === 'efisk_1_test'
                        && ($headers['X-Signature-Version'] ?? null) === '2';
                }),
                $this->identicalTo(''),
            )
            ->willReturn(new Response(200, json_encode([
                'success' => true,
                'data' => [
                    'tenant' => ['naziv' => 'T', 'pib' => '123456789', 'environment' => 'test',
                        'current_month_invoices' => 0, 'max_invoices_per_month' => 1000],
                    'statistics' => ['total_invoices' => 0, 'this_month' => 0, 'success_rate' => 0],
                    'system' => ['version' => '1.0'],
                ],
            ])));

        $this->makeStatusApi($mock)->fetch();
    }

    // --- Response body dostupan u exception ---

    public function testExceptionContainsResponseBody(): void
    {
        $body = json_encode(['success' => false, 'error' => 'Test error']);
        $mock = $this->createMock(HttpClientInterface::class);
        $mock->method('request')
            ->willReturn(new Response(500, $body));

        try {
            $config = new Config('efisk_1_test', 'secret', maxRetries: 0);
            $api = new FiskalizacijaApi($config, $mock, $this->signer);
            $api->fiskalizuj($this->makeInvoice());
            $this->fail('Trebalo je da baci ServerException');
        } catch (ServerException $e) {
            $this->assertSame($body, $e->getResponseBody());
        }
    }

    // --- Potpis v2 nad ciljem kako se salje ---

    /**
     * HTTP klijent koji beleži svaki zahtev i vraća zadate odgovore redom
     * (`NetworkException` u nizu se baca).
     *
     * @param list<Response|NetworkException> $odgovori
     */
    private static function snimac(array $odgovori): HttpClientInterface
    {
        return new class ($odgovori) implements HttpClientInterface {
            /** @var list<array{method: string, url: string, headers: array<string, string>, body: string}> */
            public array $zahtevi = [];

            /** @param list<Response|NetworkException> $odgovori */
            public function __construct(private array $odgovori)
            {
            }

            public function request(string $method, string $url, array $headers = [], string $body = ''): Response
            {
                $this->zahtevi[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                $sledeci = array_shift($this->odgovori) ?? new Response(500, '{}');
                if ($sledeci instanceof NetworkException) {
                    throw $sledeci;
                }

                return $sledeci;
            }
        };
    }

    private static function ok(): Response
    {
        return new Response(200, json_encode(['success' => true, 'data' => []]));
    }

    /** Ocekivani v2 potpis, nezavisno od HmacSigner-a. */
    private static function v2(string $tajna, string $ts, string $metoda, string $cilj, string $telo): string
    {
        return base64_encode(hash_hmac('sha256', 'v2:' . $ts . $metoda . $cilj . hash('sha256', $telo), $tajna, true));
    }

    /** @return iterable<string, array{callable(EfiskalizacijaClient): mixed, string}> */
    public static function getPozivi(): iterable
    {
        yield 'status bez query-ja' => [static fn (EfiskalizacijaClient $c) => $c->status()->fetch(), '/api/multitenant.php/status'];
        yield 'invoice limit i offset' => [static fn (EfiskalizacijaClient $c) => $c->invoices()->list(10, 20), '/api/multitenant.php/invoice?limit=10&offset=20'];
        yield 'pdf sa download' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('ZE4GVYYT-ABCD-1', true), '/api/multitenant.php/pdf?pfr=ZE4GVYYT-ABCD-1&download=1'];
        // RFC 3986: razmak je %20 (ne +), `~` ostaje nekodiran
        yield 'pdf sa razmakom, +, /, Ž, [] i &' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('A B+C/Ž[]&x=1', false), '/api/multitenant.php/pdf?pfr=A%20B%2BC%2F%C5%BD%5B%5D%26x%3D1'];
        yield 'pdf sa ~' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('A~B', true), '/api/multitenant.php/pdf?pfr=A~B&download=1'];
    }

    /**
     * Api sa javnim `get`/`getRaw` za query koji javne metode ne grade.
     */
    private function sirovApi(HttpClientInterface $http): AbstractApi
    {
        return new class ($this->config, $http, $this->signer) extends AbstractApi {
            /**
             * @param array<string, mixed> $query
             * @return array<string, mixed>
             */
            public function sirovGet(string $endpoint, array $query): array
            {
                return $this->get($endpoint, $query);
            }

            /** @param array<string, mixed> $query */
            public function sirovGetRaw(string $endpoint, array $query): string
            {
                return $this->getRaw($endpoint, $query)->body;
            }
        };
    }

    /** @return iterable<string, array{string, array<string, mixed>, string}> */
    public static function sirovQuery(): iterable
    {
        // http_build_query daje '' za same null vrednosti: bez `?` na kraju
        // (Guzzle i Symfony prazan `?` odbacuju, pa bi potpisan cilj ≠ poslat)
        yield 'get: query bez vrednosti' => ['get', ['a' => null], '/api/multitenant.php/invoice'];
        yield 'getRaw: query bez vrednosti' => ['getRaw', ['a' => null, 'b' => null], '/api/multitenant.php/invoice'];
        yield 'get: ~ i razmak' => ['get', ['q' => 'a~b c'], '/api/multitenant.php/invoice?q=a~b%20c'];
        yield 'getRaw: [] u imenu' => ['getRaw', ['f' => ['a b', 'Ž']], '/api/multitenant.php/invoice?f%5B0%5D=a%20b&f%5B1%5D=%C5%BD'];
    }

    /**
     * Query se kodira po RFC 3986 (fiksna tačka za Guzzle, Symfony i WP
     * Requests), a `?` se dodaje samo uz neprazan query.
     *
     * @param array<string, mixed> $query
     * @trace R1 R3
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('sirovQuery')]
    public function testQueryRfc3986IBezPraznogUpitnika(string $metoda, array $query, string $ocekivanCilj): void
    {
        $snimac = self::snimac([self::ok()]);
        $api = $this->sirovApi($snimac);
        $metoda === 'get' ? $api->sirovGet('/invoice', $query) : $api->sirovGetRaw('/invoice', $query);

        $z = $snimac->zahtevi[0];
        $this->assertSame(Config::BASE_URL_PRODUCTION . $ocekivanCilj, $z['url']);
        $this->assertSame(
            self::v2('secret-key-for-testing', $z['headers']['X-Timestamp'], 'GET', $ocekivanCilj, ''),
            $z['headers']['X-Signature'],
        );
    }

    /**
     * Svaki GET (sa query-jem i bez) potpisuje v2 tačno onaj cilj koji ide
     * u URL posle hosta, uz zaglavlje verzije.
     *
     * @param callable(EfiskalizacijaClient): mixed $poziv
     * @trace R1
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('getPozivi')]
    public function testGetPotpisujeCiljKakoSeSalje(callable $poziv, string $ocekivanCilj): void
    {
        $snimac = self::snimac([self::ok()]);
        $poziv(new EfiskalizacijaClient($this->config, $snimac));

        $this->assertCount(1, $snimac->zahtevi);
        $z = $snimac->zahtevi[0];
        $this->assertSame('GET', $z['method']);
        $this->assertSame(Config::BASE_URL_PRODUCTION . $ocekivanCilj, $z['url']);
        $this->assertSame('2', $z['headers']['X-Signature-Version'] ?? null);
        $this->assertSame(
            self::v2('secret-key-for-testing', $z['headers']['X-Timestamp'], 'GET', $ocekivanCilj, ''),
            $z['headers']['X-Signature'],
        );
    }

    /** @trace R1 */
    public function testPostPotpisujeCiljITeloV2(): void
    {
        $snimac = self::snimac([new Response(200, json_encode([
            'success' => true,
            'data' => ['rezultat' => ['pfr_broj' => 'P-1', 'racun_id' => 1]],
        ]))]);
        (new EfiskalizacijaClient($this->config, $snimac))->fiskalizacija()->fiskalizuj($this->makeInvoice());

        $z = $snimac->zahtevi[0];
        $this->assertSame('POST', $z['method']);
        $this->assertSame(Config::BASE_URL_PRODUCTION . '/api/multitenant.php/fiskalizacija', $z['url']);
        $this->assertNotSame('', $z['body']);
        $this->assertSame('2', $z['headers']['X-Signature-Version'] ?? null);
        $this->assertSame(
            self::v2('secret-key-for-testing', $z['headers']['X-Timestamp'], 'POST', '/api/multitenant.php/fiskalizacija', $z['body']),
            $z['headers']['X-Signature'],
        );
    }

    /**
     * Bazni URL sa putanjom (samo iza gateway-a koji skida prefiks): URL
     * nosi prefiks, a potpisuje se cilj BEZ prefiksa baze — server je uvek
     * na korenu hosta i iza takvog gateway-a vidi cilj bez prefiksa.
     *
     * @trace R1
     */
    public function testBazniUrlSaPutanjomPotpisujeCiljBezPrefiksa(): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', 'https://primer.test/pod/', maxRetries: 0);
        $snimac = self::snimac([self::ok()]);
        (new EfiskalizacijaClient($config, $snimac))->invoices()->list(5, 0);

        $z = $snimac->zahtevi[0];
        $cilj = '/api/multitenant.php/invoice?limit=5&offset=0';
        $this->assertSame('https://primer.test/pod' . $cilj, $z['url']);
        $ts = $z['headers']['X-Timestamp'];
        $this->assertSame(self::v2('secret-key-for-testing', $ts, 'GET', $cilj, ''), $z['headers']['X-Signature']);
        $this->assertNotSame(self::v2('secret-key-for-testing', $ts, 'GET', '/pod' . $cilj, ''), $z['headers']['X-Signature']);
    }

    // --- R2: timestamp po pokusaju ---

    /** @return iterable<string, array{list<Response|NetworkException>}> */
    public static function nizoviPonavljanja(): iterable
    {
        yield '503, 503, 503 pa 200' => [[new Response(503, '{}'), new Response(503, '{}'), new Response(503, '{}'), self::ok()]];
        yield 'mrezna greska dvaput pa 200' => [[new NetworkException('timeout', 28), new NetworkException('timeout', 28), self::ok()]];
    }

    /**
     * Pravi sat, razmak 1 ms: svaki ponovni pokušaj ima strogo veći
     * `X-Timestamp` (pa drugi potpis); i-ti pokušaj je ispred sata najviše
     * i − 1 sekundi (pokušaji brži od 1 s ne čekaju sledeći sekund).
     *
     * @param list<Response|NetworkException> $odgovori
     * @trace R2
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nizoviPonavljanja')]
    public function testSvakiPokusajImaStrogoVeciTimestamp(array $odgovori): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 3, retryBaseDelayMs: 1, retryMultiplier: 1);
        $snimac = self::snimac($odgovori);
        $pre = time();
        (new EfiskalizacijaClient($config, $snimac))->status()->fetch();
        $posle = time();

        $this->assertCount(count($odgovori), $snimac->zahtevi);
        $vremena = array_map(static fn (array $z): int => (int) $z['headers']['X-Timestamp'], $snimac->zahtevi);
        for ($i = 1; $i < count($vremena); $i++) {
            $this->assertGreaterThan($vremena[$i - 1], $vremena[$i], 'pokusaj ' . ($i + 1));
            $this->assertLessThanOrEqual($posle + $i, $vremena[$i], 'pomak pokusaja ' . ($i + 1));
        }
        $potpisi = array_map(static fn (array $z): string => $z['headers']['X-Signature'], $snimac->zahtevi);
        $this->assertSame($potpisi, array_values(array_unique($potpisi)));
        $this->assertGreaterThanOrEqual($pre, $vremena[0]);
        $this->assertLessThanOrEqual($posle, $vremena[0], 'prvi pokusaj nosi tekuci sat');
        foreach ($snimac->zahtevi as $z) {
            $this->assertSame(self::v2('secret-key-for-testing', $z['headers']['X-Timestamp'], 'GET', '/api/multitenant.php/status', ''), $z['headers']['X-Signature']);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function razmaciPonavljanja(): iterable
    {
        yield '0 ms' => [0];
        yield '10 ms' => [10];
        yield '100 ms' => [100];
    }

    /**
     * Pravi sat i prava pauza: ponavljanje ne čeka sledeći sekund. 1.0.2 je
     * za 3 ponavljanja trošio 0,00 / 0,04 / 0,32 s (razmak 0 / 10 / 100 ms,
     * multiplikator 1); čekanje na sledeći sekund dodalo bi ~1 s po pokušaju.
     * Timestampovi strogo rastu, a pomak u odnosu na sat posle poziva je
     * najviše k (broj ponovljenih pokušaja).
     *
     * @trace R2
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('razmaciPonavljanja')]
    public function testPonavljanjeNeCekaSledeciSekund(int $razmakMs): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 3, retryBaseDelayMs: $razmakMs, retryMultiplier: 1);
        $snimac = self::snimac([new Response(503, '{}'), new Response(503, '{}'), new Response(503, '{}'), self::ok()]);
        $pre = time();
        $t0 = microtime(true);
        (new EfiskalizacijaClient($config, $snimac))->status()->fetch();
        $trajanje = microtime(true) - $t0;
        $posle = time();

        $this->assertLessThan(3 * $razmakMs / 1000 + 0.5, $trajanje, 'bez dodatnog cekanja');
        $vremena = array_map(static fn (array $z): int => (int) $z['headers']['X-Timestamp'], $snimac->zahtevi);
        $this->assertCount(4, $vremena);
        for ($i = 1; $i < 4; $i++) {
            $this->assertGreaterThan($vremena[$i - 1], $vremena[$i], 'pokusaj ' . ($i + 1));
        }
        $this->assertGreaterThanOrEqual($pre, $vremena[0]);
        $this->assertLessThanOrEqual(3, max($vremena) - $posle, 'pomak <= k');
    }

    /**
     * Sat i pauza kao neinicijalizovana svojstva: SDK koristi `time()` i
     * `usleep()`, zahtev i ponovni pokušaj prolaze. To stanje pravi samo
     * eksplicitan `unset()` (kao ovde). Objekat iz `unserialize()` (i oblika
     * 1.0.2) i podklasa koja ne zove roditeljski konstruktor imaju `$clock`
     * i `$sleep` = null (podrazumevana vrednost svojstva); neinicijalizovana
     * ostaju samo svojstva bez podrazumevane vrednosti, npr. `$config` kod
     * podklase bez roditeljskog konstruktora.
     *
     * @trace R2
     */
    public function testNeinicijalizovanSatIPauzaKoristeTimeIUsleep(): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 1, retryBaseDelayMs: 0);
        $snimac = self::snimac([new Response(503, '{}'), self::ok()]);
        $api = new StatusApi($config, $snimac, new HmacSigner($config->getApiSecret()));
        \Closure::bind(function (): void {
            unset($this->clock, $this->sleep);
        }, $api, AbstractApi::class)();
        $pre = time();

        $api->fetch();

        $this->assertCount(2, $snimac->zahtevi);
        $vremena = array_map(static fn (array $z): int => (int) $z['headers']['X-Timestamp'], $snimac->zahtevi);
        $this->assertGreaterThanOrEqual($pre, $vremena[0]);
        $this->assertLessThanOrEqual(time(), $vremena[0]);
        $this->assertGreaterThan($vremena[0], $vremena[1]);
    }

    /**
     * `arg_separator.output` iz php.ini ne menja query: separator je uvek `&`.
     *
     * @trace R1 R3
     */
    public function testQuerySeparatorNeZavisiOdIni(): void
    {
        $staro = ini_get('arg_separator.output');
        ini_set('arg_separator.output', '&amp;');
        try {
            $snimac = self::snimac([self::ok()]);
            $this->sirovApi($snimac)->sirovGet('/invoice', ['a' => '1', 'b' => '2']);
        } finally {
            ini_set('arg_separator.output', (string) $staro);
        }

        $z = $snimac->zahtevi[0];
        $this->assertSame(Config::BASE_URL_PRODUCTION . '/api/multitenant.php/invoice?a=1&b=2', $z['url']);
        $this->assertSame(
            self::v2('secret-key-for-testing', $z['headers']['X-Timestamp'], 'GET', '/api/multitenant.php/invoice?a=1&b=2', ''),
            $z['headers']['X-Signature'],
        );
    }

    /**
     * Api sa satom i pauzom pod kontrolom testa, kroz opcione parametre
     * konstruktora `clock` i `sleep` (ne kroz metode podklase).
     *
     * @param list<int> $sat Vrednosti koje sat vraća redom (poslednja se ponavlja)
     * @return array{AbstractApi, \stdClass} Api i stanje: `pauze`, `satPriZahtevu`
     */
    private function apiSaSatom(Config $config, HttpClientInterface $http, array $sat): array
    {
        $stanje = new \stdClass();
        $stanje->sat = $sat;
        $stanje->tekuci = null;
        $stanje->pauze = [];
        $stanje->satPriZahtevu = [];

        $sat = static function () use ($stanje): int {
            $stanje->tekuci = count($stanje->sat) > 1 ? array_shift($stanje->sat) : $stanje->sat[0];

            return $stanje->tekuci;
        };
        $pauza = static function (int $microseconds) use ($stanje): void {
            $stanje->pauze[] = $microseconds;
        };
        // Beleži sat koji je SDK poslednji pročitao pre svakog zahteva
        $belezi = new class ($http, $stanje) implements HttpClientInterface {
            public function __construct(private HttpClientInterface $http, private \stdClass $stanje)
            {
            }

            public function request(string $method, string $url, array $headers = [], string $body = ''): Response
            {
                $this->stanje->satPriZahtevu[] = $this->stanje->tekuci;

                return $this->http->request($method, $url, $headers, $body);
            }
        };

        $api = new class ($config, $belezi, new HmacSigner($config->getApiSecret()), null, $sat, $pauza) extends AbstractApi {
            /** @return array<string, mixed> */
            public function status(): array
            {
                return $this->get('/status');
            }
        };

        return [$api, $stanje];
    }

    /**
     * Timestamp pokušaja je max(sat, prethodni + 1): strogo raste bez
     * čekanja. Odstupanje od TEKUĆEG sata je tada najviše k (k = broj
     * ponovljenih pokušaja do tog trenutka), odnosno J + k kad sat skoči
     * unazad za J — tvrdi se tačno.
     *
     * @return iterable<string, array{list<int>, list<int>, int}>
     */
    public static function satovi(): iterable
    {
        yield 'sat stoji' => [[1705000000], [1705000000, 1705000001, 1705000002, 1705000003], 3];
        yield 'sat ide unazad za 10 s' => [[1705000000, 1704999990], [1705000000, 1705000001, 1705000002, 1705000003], 13];
        yield 'sat ide sporije od pokusaja' => [[1705000000, 1705000000, 1705000001, 1705000001], [1705000000, 1705000001, 1705000002, 1705000003], 2];
        // Sat odmakne više od 1 s: uzima se sat, ne prethodni + 1
        yield 'sat skoci napred' => [[1705000000, 1705000005], [1705000000, 1705000005, 1705000006, 1705000007], 2];
        yield 'sat ide brze od pokusaja' => [[1705000000, 1705000002, 1705000004, 1705000006], [1705000000, 1705000002, 1705000004, 1705000006], 0];
    }

    /**
     * @param list<int> $sat
     * @param list<int> $ocekivano
     * @trace R2
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('satovi')]
    public function testTimestampRasteIKadSatNeRaste(array $sat, array $ocekivano, int $odstupanje): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 3, retryBaseDelayMs: 1);
        $snimac = self::snimac([new Response(503, '{}'), new Response(503, '{}'), new Response(503, '{}'), self::ok()]);
        [$api, $stanje] = $this->apiSaSatom($config, $snimac, $sat);
        $api->status();

        $vremena = array_map(static fn (array $z): int => (int) $z['headers']['X-Timestamp'], $snimac->zahtevi);
        $this->assertSame($ocekivano, $vremena);
        // Odstupanje od sata koji je SDK pročitao pred svaki zahtev
        $this->assertCount(4, $stanje->satPriZahtevu);
        $razlike = array_map(static fn (int $ts, int $s): int => $ts - $s, $vremena, $stanje->satPriZahtevu);
        $this->assertSame($odstupanje, max($razlike));
        $this->assertGreaterThanOrEqual(0, min($razlike));
        // Jedine pauze su razmaci ponavljanja (1, 2, 4 ms): nema čekanja na sat
        $this->assertSame([1000, 2000, 4000], $stanje->pauze);
    }

    /**
     * Podrazumevana pauza (bez zadatog `sleep`) stvarno čeka razmak
     * ponavljanja: 3 ponavljanja × 100 ms traju najmanje 0,3 s. Donja
     * granica obara pauzu koja ne čeka ili čeka u pogrešnim jedinicama
     * (`testPonavljanjeNeCekaSledeciSekund` meri samo gornju).
     *
     * @trace R2
     */
    public function testPodrazumevanaPauzaStvarnoCekaRazmak(): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 3, retryBaseDelayMs: 100, retryMultiplier: 1);
        $snimac = self::snimac([new Response(503, '{}'), new Response(503, '{}'), new Response(503, '{}'), self::ok()]);
        $t0 = microtime(true);
        (new StatusApi($config, $snimac, new HmacSigner($config->getApiSecret())))->fetch();
        $trajanje = microtime(true) - $t0;

        $this->assertCount(4, $snimac->zahtevi);
        $this->assertGreaterThanOrEqual(0.3, $trajanje);
    }

    /**
     * Pauza posle mrežne greške je razmak ponavljanja u mikrosekundama
     * (1 i 2 ms uz multiplikator 2), isto kao posle 503.
     *
     * @trace R2
     */
    public function testPauzaPosleMrezneGreskeJeRazmakUMikrosekundama(): void
    {
        $config = new Config('efisk_1_test', 'secret-key-for-testing', maxRetries: 2, retryBaseDelayMs: 1);
        $snimac = self::snimac([new NetworkException('timeout', 28), new NetworkException('timeout', 28), self::ok()]);
        [$api, $stanje] = $this->apiSaSatom($config, $snimac, [1705000000]);
        $api->status();

        $this->assertCount(3, $snimac->zahtevi);
        $this->assertSame([1000, 2000], $stanje->pauze);
    }
}
