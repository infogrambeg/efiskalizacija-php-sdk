<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Auth\HmacSigner;
use PHPUnit\Framework\TestCase;

final class HmacSignerTest extends TestCase
{
    private HmacSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new HmacSigner('test-secret-64-chars-long-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx');
    }

    public function testSignGeneratesBase64String(): void
    {
        $signature = $this->signer->sign('POST', '/api/multitenant.php/fiskalizacija', '{"test":true}', 1705000000);

        $this->assertNotEmpty($signature);
        // Base64 decoded trebao bi biti 32 bajta (SHA256 = 256 bit)
        $decoded = base64_decode($signature, true);
        $this->assertNotFalse($decoded);
        $this->assertSame(32, strlen($decoded));
    }

    public function testSignIsReproducible(): void
    {
        $sig1 = $this->signer->sign('POST', '/api/multitenant.php/fiskalizacija', '{"test":true}', 1705000000);
        $sig2 = $this->signer->sign('POST', '/api/multitenant.php/fiskalizacija', '{"test":true}', 1705000000);

        $this->assertSame($sig1, $sig2);
    }

    public function testSignDiffersWithDifferentTimestamp(): void
    {
        $sig1 = $this->signer->sign('POST', '/api/test', '', 1705000000);
        $sig2 = $this->signer->sign('POST', '/api/test', '', 1705000001);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignDiffersWithDifferentBody(): void
    {
        $sig1 = $this->signer->sign('POST', '/api/test', '{"a":1}', 1705000000);
        $sig2 = $this->signer->sign('POST', '/api/test', '{"a":2}', 1705000000);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignDiffersWithDifferentMethod(): void
    {
        $sig1 = $this->signer->sign('GET', '/api/test', '', 1705000000);
        $sig2 = $this->signer->sign('POST', '/api/test', '', 1705000000);

        $this->assertNotSame($sig1, $sig2);
    }

    public function testSignMethodIsUppercased(): void
    {
        $sig1 = $this->signer->sign('post', '/api/test', '', 1705000000);
        $sig2 = $this->signer->sign('POST', '/api/test', '', 1705000000);

        $this->assertSame($sig1, $sig2);
    }

    /**
     * Verifikacija 1:1 kompatibilnosti sa serverskim algoritmom.
     * Serverski algoritam: stringToSign = "{timestamp}{METHOD}{path}{bodyHash}"
     */
    public function testSignMatchesServerAlgorithm(): void
    {
        $secret = 'my-test-secret';
        $signer = new HmacSigner($secret);

        $method = 'POST';
        $path = '/api/multitenant.php/fiskalizacija';
        $body = '{"stavke":[{"naziv":"Test","kolicina":1,"jedinicna_cena":100,"pdv_stopa":20}]}';
        $timestamp = 1705000000;

        $signature = $signer->sign($method, $path, $body, $timestamp);

        // Rucno reproduce-uj serverski algoritam
        $bodyHash = hash('sha256', $body);
        $stringToSign = $timestamp . strtoupper($method) . $path . $bodyHash;
        $expected = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        $this->assertSame($expected, $signature);
    }

    public function testGetAuthHeadersReturnsAllRequired(): void
    {
        $headers = $this->signer->getAuthHeaders(
            'efisk_1_abc123',
            'POST',
            '/api/multitenant.php/fiskalizacija',
            '{}',
            1705000000,
        );

        $this->assertArrayHasKey('X-API-Key', $headers);
        $this->assertArrayHasKey('X-Timestamp', $headers);
        $this->assertArrayHasKey('X-Signature', $headers);

        $this->assertSame('efisk_1_abc123', $headers['X-API-Key']);
        $this->assertSame('1705000000', $headers['X-Timestamp']);
        $this->assertNotEmpty($headers['X-Signature']);
    }

    public function testGetAuthHeadersUsesCurrentTimeByDefault(): void
    {
        $before = time();
        $headers = $this->signer->getAuthHeaders('key', 'GET', '/test', '');
        $after = time();

        $timestamp = (int) $headers['X-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testEmptyBodyProducesValidSignature(): void
    {
        $signature = $this->signer->sign('GET', '/api/multitenant.php/status', '', 1705000000);

        $this->assertNotEmpty($signature);
        $decoded = base64_decode($signature, true);
        $this->assertNotFalse($decoded);
    }
}
