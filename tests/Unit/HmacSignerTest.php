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

    public function testSignStripsQueryStringFromPath(): void
    {
        $pathWithQuery = '/api/multitenant.php/pdf?pfr=ABC123&download=1';
        $pathWithoutQuery = '/api/multitenant.php/pdf';

        $sig1 = $this->signer->sign('GET', $pathWithQuery, '', 1705000000);
        $sig2 = $this->signer->sign('GET', $pathWithoutQuery, '', 1705000000);

        $this->assertSame($sig1, $sig2, 'Signature treba da bude ista sa i bez query stringa');
    }

    public function testSignWithQueryStringMatchesServerAlgorithm(): void
    {
        $secret = 'my-test-secret';
        $signer = new HmacSigner($secret);

        $pathWithQuery = '/api/multitenant.php/pdf?pfr=ZE4GVYYT&download=1';
        $timestamp = 1705000000;

        $signature = $signer->sign('GET', $pathWithQuery, '', $timestamp);

        // Server koristi parse_url(REQUEST_URI, PHP_URL_PATH) - samo path deo.
        $serverPath = parse_url($pathWithQuery, PHP_URL_PATH);
        $bodyHash = hash('sha256', '');
        $stringToSign = $timestamp . 'GET' . $serverPath . $bodyHash;
        $expected = base64_encode(hash_hmac('sha256', $stringToSign, $secret, true));

        $this->assertSame($expected, $signature);
    }

    public function testEmptyBodyProducesValidSignature(): void
    {
        $signature = $this->signer->sign('GET', '/api/multitenant.php/status', '', 1705000000);

        $this->assertNotEmpty($signature);
        $decoded = base64_decode($signature, true);
        $this->assertNotFalse($decoded);
    }

    // ========== v1 ostaje kao u 1.0.2 (kompatibilnost direktnih pozivalaca) ==========

    /**
     * `sign()` i `getAuthHeaders()` daju tačno ono što daje 1.0.2: vektori su
     * izračunati objavljenim `v1.0.2` (`tests/Fixtures/php-sdk-v1.0.2` u
     * glavnom repou), a niz zaglavlja nema `X-Signature-Version`.
     *
     * @trace R1
     */
    public function testSignIGetAuthHeadersOstajuV1IzVerzije102(): void
    {
        $signer = new HmacSigner('my-test-secret');

        $this->assertSame(
            '3Ps2Ap9+Kel/nwfECXRa5mqsLOZTU4TbsKshW7w3d8A=',
            $signer->sign('GET', '/api/multitenant.php/pdf?pfr=ZE4GVYYT&download=1', '', 1705000000),
        );
        $this->assertSame(
            'VxvDlIa+109VjCQc9+5G4LNNyl0su3yA3QE3Gm8UCSM=',
            $signer->sign('POST', '/api/multitenant.php/fiskalizacija', '{"a":1}', 1705000000),
        );
        $this->assertSame(
            [
                'X-API-Key' => 'k',
                'X-Timestamp' => '1705000000',
                'X-Signature' => '38a4TB0SmNuY2/SSDJGT2ax9vDgsmsSHvNUqkeErd9Y=',
            ],
            $signer->getAuthHeaders('k', 'GET', '/api/multitenant.php/status', '', 1705000000),
        );
    }

    // ========== v2: signV2() i getAuthHeadersV2() ==========

    /**
     * Serverski algoritam v2: stringToSign = "v2:{timestamp}{METHOD}{request-target}{bodyHash}"
     *
     * @trace R1
     */
    public function testSignV2MatchesServerAlgorithm(): void
    {
        $secret = 'my-test-secret';
        $signer = new HmacSigner($secret);
        $body = '{"stavke":[{"naziv":"Test","kolicina":1,"jedinicna_cena":100,"pdv_stopa":20}]}';

        $expected = base64_encode(hash_hmac(
            'sha256',
            'v2:1705000000POST/api/multitenant.php/fiskalizacija' . hash('sha256', $body),
            $secret,
            true,
        ));

        $this->assertSame($expected, $signer->signV2('post', '/api/multitenant.php/fiskalizacija', $body, 1705000000));
    }

    /**
     * Vektori izračunati serverskim `HmacAuthenticator::generateSignatureV2()`
     * (glavni repo, K6a) — nezavisno od prepisa algoritma u ovom testu.
     *
     * @trace R1
     */
    public function testSignV2MatchesServerV2Vectors(): void
    {
        $signer = new HmacSigner('my-test-secret');

        $this->assertSame(
            'jYj6XJQEGyu2AGCPmKsbhLq6/CIaX5fv/45YbCF/knU=',
            $signer->signV2('GET', '/api/multitenant.php/pdf?pfr=ZE4GVYYT&download=1', '', 1705000000),
        );
        $this->assertSame(
            'RmE1HZRKpYgYYVY2gZUVfSTjrqcJrQ+p0F9V4wUnSBg=',
            $signer->signV2('POST', '/api/multitenant.php/fiskalizacija', '{"a":1}', 1705000000),
        );
    }

    /**
     * v2 potpis nije v1 potpis iste putanje ni kad cilj nema query (prefiks `v2:`).
     *
     * @trace R1
     */
    public function testSignV2IsNotV1Signature(): void
    {
        $path = '/api/multitenant.php/status';

        $this->assertNotSame(
            $this->signer->sign('GET', $path, '', 1705000000),
            $this->signer->signV2('GET', $path, '', 1705000000),
        );
    }

    /** @trace R1 */
    public function testGetAuthHeadersV2ReturnsAllRequired(): void
    {
        $headers = $this->signer->getAuthHeadersV2(
            'efisk_1_abc123',
            'GET',
            '/api/multitenant.php/pdf?pfr=A&download=1',
            '',
            1705000000,
        );

        $this->assertSame(
            [
                'X-API-Key' => 'efisk_1_abc123',
                'X-Timestamp' => '1705000000',
                'X-Signature' => $this->signer->signV2('GET', '/api/multitenant.php/pdf?pfr=A&download=1', '', 1705000000),
                'X-Signature-Version' => '2',
            ],
            $headers,
        );
    }

    /** @trace R1 */
    public function testGetAuthHeadersV2UsesCurrentTimeByDefault(): void
    {
        $before = time();
        $headers = $this->signer->getAuthHeadersV2('key', 'GET', '/test?a=1', '');
        $after = time();

        $timestamp = (int) $headers['X-Timestamp'];
        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
        $this->assertSame($this->signer->signV2('GET', '/test?a=1', '', $timestamp), $headers['X-Signature']);
    }

    /**
     * v2 potpisuje query string: potpis sa i bez query-ja se razlikuje
     * (u v1 je isti, vidi testSignStripsQueryStringFromPath).
     *
     * @trace R1
     */
    public function testSignV2IncludesQueryString(): void
    {
        $this->assertNotSame(
            $this->signer->signV2('GET', '/api/multitenant.php/pdf?pfr=ABC123&download=1', '', 1705000000),
            $this->signer->signV2('GET', '/api/multitenant.php/pdf', '', 1705000000),
            'Query string mora uci u potpis',
        );
    }

    /**
     * Cilj se potpisuje tačno kako se šalje: bez dekodiranja, sortiranja i
     * normalizacije query-ja.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function razliciteForme(): iterable
    {
        yield 'redosled parametara' => ['?a=1&b=2', '?b=2&a=1'];
        yield 'razmak kao + i kao %20' => ['?q=a+b', '?q=a%20b'];
        yield 'kodiran i sirov /' => ['?q=a%2Fb', '?q=a/b'];
        yield 'mala i velika heks slova' => ['?q=%C5%BD', '?q=%c5%bd'];
        yield 'prazan query' => ['?', ''];
    }

    /** @trace R1 */
    #[\PHPUnit\Framework\Attributes\DataProvider('razliciteForme')]
    public function testSignV2DoesNotNormalizeQuery(string $a, string $b): void
    {
        $base = '/api/multitenant.php/invoice';

        $this->assertNotSame(
            $this->signer->signV2('GET', $base . $a, '', 1705000000),
            $this->signer->signV2('GET', $base . $b, '', 1705000000),
        );
    }
}
