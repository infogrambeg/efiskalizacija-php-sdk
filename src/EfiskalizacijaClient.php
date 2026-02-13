<?php

declare(strict_types=1);

namespace Efiskalizacija;

use Efiskalizacija\Api\EmailApi;
use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Api\InvoiceApi;
use Efiskalizacija\Api\PdfApi;
use Efiskalizacija\Api\StatusApi;
use Efiskalizacija\Api\TestApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Http\CurlHttpClient;
use Efiskalizacija\Http\HttpClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Glavna fasada za eFiskalizacija API.
 *
 * Primer upotrebe:
 *
 *     $client = EfiskalizacijaClient::create($apiKey, $apiSecret);
 *     $result = $client->fiskalizacija()->fiskalizuj($invoice);
 *     $status = $client->status()->fetch();
 *     $pdf    = $client->pdf()->download($pfrBroj);
 */
final class EfiskalizacijaClient
{
    private Config $config;
    private HttpClientInterface $httpClient;
    private HmacSigner $signer;
    private ?LoggerInterface $logger;

    private ?FiskalizacijaApi $fiskalizacijaApi = null;
    private ?StatusApi $statusApi = null;
    private ?InvoiceApi $invoiceApi = null;
    private ?PdfApi $pdfApi = null;
    private ?EmailApi $emailApi = null;
    private ?TestApi $testApi = null;

    public function __construct(
        Config $config,
        ?HttpClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->config = $config;
        $this->httpClient = $httpClient ?? new CurlHttpClient(
            $config->getTimeout(),
            $config->getConnectTimeout(),
        );
        $this->signer = new HmacSigner($config->getApiSecret());
        $this->logger = $logger;
    }

    /**
     * Brzo kreiranje klijenta.
     */
    public static function create(
        string $apiKey,
        string $apiSecret,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            new Config($apiKey, $apiSecret),
            logger: $logger,
        );
    }

    /**
     * Kreiranje sandbox klijenta za testiranje.
     */
    public static function sandbox(
        string $apiKey,
        string $apiSecret,
        ?LoggerInterface $logger = null,
    ): self {
        return new self(
            Config::sandbox($apiKey, $apiSecret),
            logger: $logger,
        );
    }

    /** Fiskalizacija racuna (POST /fiskalizacija). */
    public function fiskalizacija(): FiskalizacijaApi
    {
        return $this->fiskalizacijaApi ??= new FiskalizacijaApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    /** Status tenanta (GET /status). */
    public function status(): StatusApi
    {
        return $this->statusApi ??= new StatusApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    /** Lista racuna (GET /invoice). */
    public function invoices(): InvoiceApi
    {
        return $this->invoiceApi ??= new InvoiceApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    /** PDF racuna (GET /pdf). */
    public function pdf(): PdfApi
    {
        return $this->pdfApi ??= new PdfApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    /** Slanje racuna na email (POST /send-email). */
    public function email(): EmailApi
    {
        return $this->emailApi ??= new EmailApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    /** Test fiskalizacija - samo sandbox (POST /test). */
    public function test(): TestApi
    {
        return $this->testApi ??= new TestApi(
            $this->config, $this->httpClient, $this->signer, $this->logger,
        );
    }

    public function getConfig(): Config
    {
        return $this->config;
    }
}
