<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\EmailApi;
use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Api\InvoiceApi;
use Efiskalizacija\Api\PdfApi;
use Efiskalizacija\Api\StatusApi;
use Efiskalizacija\Api\TestApi;
use Efiskalizacija\Config;
use Efiskalizacija\EfiskalizacijaClient;
use PHPUnit\Framework\TestCase;

final class EfiskalizacijaClientTest extends TestCase
{
    public function testCreateFactory(): void
    {
        $client = EfiskalizacijaClient::create('efisk_1_abc', 'secret123');

        $this->assertInstanceOf(EfiskalizacijaClient::class, $client);
        $this->assertSame('efisk_1_abc', $client->getConfig()->getApiKey());
    }

    public function testSandboxFactory(): void
    {
        $client = EfiskalizacijaClient::sandbox('efisk_1_abc', 'secret123');

        $this->assertSame(Config::BASE_URL_SANDBOX, $client->getConfig()->getBaseUrl());
    }

    public function testApiAccessorsReturnCorrectTypes(): void
    {
        $client = EfiskalizacijaClient::create('key', 'secret');

        $this->assertInstanceOf(FiskalizacijaApi::class, $client->fiskalizacija());
        $this->assertInstanceOf(StatusApi::class, $client->status());
        $this->assertInstanceOf(InvoiceApi::class, $client->invoices());
        $this->assertInstanceOf(PdfApi::class, $client->pdf());
        $this->assertInstanceOf(EmailApi::class, $client->email());
        $this->assertInstanceOf(TestApi::class, $client->test());
    }

    public function testApiAccessorsReturnSameInstance(): void
    {
        $client = EfiskalizacijaClient::create('key', 'secret');

        $fisk1 = $client->fiskalizacija();
        $fisk2 = $client->fiskalizacija();

        $this->assertSame($fisk1, $fisk2);
    }

    public function testCustomConfig(): void
    {
        $config = new Config('key', 'secret', 'https://custom.api.com', timeout: 60);
        $client = new EfiskalizacijaClient($config);

        $this->assertSame('https://custom.api.com', $client->getConfig()->getBaseUrl());
        $this->assertSame(60, $client->getConfig()->getTimeout());
    }
}
