<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Api\StatusApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
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

        $result = $api->fiskalizuj($this->makeInvoice());

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

        $result = $api->fiskalizuj($this->makeInvoice());

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

        $api->fiskalizuj($this->makeInvoice());
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

        $result = $api->fiskalizuj($this->makeInvoice());

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

        $api->fiskalizuj($this->makeInvoice());
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
                        && $headers['X-API-Key'] === 'efisk_1_test';
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
}
