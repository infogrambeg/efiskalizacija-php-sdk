<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testCreateWithDefaults(): void
    {
        $config = new Config('efisk_1_abc', 'secret123');

        $this->assertSame('efisk_1_abc', $config->getApiKey());
        $this->assertSame('secret123', $config->getApiSecret());
        $this->assertSame(Config::BASE_URL_PRODUCTION, $config->getBaseUrl());
        $this->assertSame(30, $config->getTimeout());
        $this->assertSame(10, $config->getConnectTimeout());
        $this->assertSame(3, $config->getMaxRetries());
    }

    public function testSandboxFactory(): void
    {
        $config = Config::sandbox('key', 'secret');

        $this->assertSame(Config::BASE_URL_SANDBOX, $config->getBaseUrl());
    }

    public function testProductionFactory(): void
    {
        $config = Config::production('key', 'secret');

        $this->assertSame(Config::BASE_URL_PRODUCTION, $config->getBaseUrl());
    }

    public function testEmptyApiKeyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config('', 'secret');
    }

    public function testEmptyApiSecretThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Config('key', '');
    }

    public function testBaseUrlTrailingSlashRemoved(): void
    {
        $config = new Config('key', 'secret', 'https://example.com/');

        $this->assertSame('https://example.com', $config->getBaseUrl());
    }

    public function testCustomRetryConfig(): void
    {
        $config = new Config(
            apiKey: 'key',
            apiSecret: 'secret',
            maxRetries: 5,
            retryBaseDelayMs: 500,
            retryMultiplier: 3,
        );

        $this->assertSame(5, $config->getMaxRetries());
        $this->assertSame(500, $config->getRetryBaseDelayMs());
        $this->assertSame(3, $config->getRetryMultiplier());
    }
}
