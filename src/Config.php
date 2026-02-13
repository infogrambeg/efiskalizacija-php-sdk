<?php

declare(strict_types=1);

namespace Efiskalizacija;

final class Config
{
    public const BASE_URL_PRODUCTION = 'https://efiskalizacija.cloud';
    public const BASE_URL_SANDBOX = 'https://staging.efiskalizacija.cloud';

    public const DEFAULT_TIMEOUT = 30;
    public const DEFAULT_CONNECT_TIMEOUT = 10;
    public const DEFAULT_MAX_RETRIES = 3;
    public const DEFAULT_RETRY_BASE_DELAY_MS = 1000;
    public const DEFAULT_RETRY_MULTIPLIER = 2;

    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;
    private int $timeout;
    private int $connectTimeout;
    private int $maxRetries;
    private int $retryBaseDelayMs;
    private int $retryMultiplier;

    public function __construct(
        string $apiKey,
        string $apiSecret,
        string $baseUrl = self::BASE_URL_PRODUCTION,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $maxRetries = self::DEFAULT_MAX_RETRIES,
        int $retryBaseDelayMs = self::DEFAULT_RETRY_BASE_DELAY_MS,
        int $retryMultiplier = self::DEFAULT_RETRY_MULTIPLIER,
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key ne sme biti prazan');
        }
        if ($apiSecret === '') {
            throw new \InvalidArgumentException('API secret ne sme biti prazan');
        }

        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
        $this->maxRetries = $maxRetries;
        $this->retryBaseDelayMs = $retryBaseDelayMs;
        $this->retryMultiplier = $retryMultiplier;
    }

    public static function sandbox(string $apiKey, string $apiSecret): self
    {
        return new self($apiKey, $apiSecret, self::BASE_URL_SANDBOX);
    }

    public static function production(string $apiKey, string $apiSecret): self
    {
        return new self($apiKey, $apiSecret, self::BASE_URL_PRODUCTION);
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function getApiSecret(): string
    {
        return $this->apiSecret;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getConnectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function getRetryBaseDelayMs(): int
    {
        return $this->retryBaseDelayMs;
    }

    public function getRetryMultiplier(): int
    {
        return $this->retryMultiplier;
    }
}
