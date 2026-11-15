<?php

declare(strict_types=1);

namespace Efiskalizacija\Auth;

/**
 * HMAC-SHA256 potpis za eFiskalizacija API, verzije 1 i 2.
 *
 * v1 ({@see self::sign()}, {@see self::getAuthHeaders()}) — ponašanje je
 * bajt-isto kao u 1.0.2, radi postojećih direktnih pozivalaca; 1:1 sa
 * serverskim HmacAuthenticator::generateSignature():
 *   1. bodyHash = SHA256(requestBody)
 *   2. stringToSign = "{timestamp}{METHOD}{path}{bodyHash}"  (path BEZ query-ja)
 *   3. signature = Base64(HMAC-SHA256(apiSecret, stringToSign))
 *
 * v2 ({@see self::signV2()}, {@see self::getAuthHeadersV2()}, od 1.1.0) —
 * 1:1 sa serverskim HmacAuthenticator::generateSignatureV2():
 *   stringToSign = "v2:{timestamp}{METHOD}{request-target}{bodyHash}"
 * request-target je putanja + "?" + query TAČNO kako se šalje u URL-u (bez
 * dekodiranja, sortiranja i normalizacije), pa server odbija zahtev čiji je
 * query izmenjen posle potpisa. Zahtev mora nositi zaglavlje
 * `X-Signature-Version: 2`. SDK klijent (`AbstractApi`) potpisuje samo v2.
 */
final class HmacSigner
{
    /** Vrednost zaglavlja `X-Signature-Version` za potpis v2. */
    public const SIGNATURE_VERSION = '2';

    /** Doslovni prefiks stringa za potpis verzije 2. */
    public const V2_PREFIX = 'v2:';

    private string $apiSecret;

    public function __construct(#[\SensitiveParameter] string $apiSecret)
    {
        $this->apiSecret = $apiSecret;
    }

    /**
     * Generiše HMAC-SHA256 potpis v1 (kao u 1.0.2: query se ne potpisuje).
     *
     * @param string $method   HTTP metod (GET, POST)
     * @param string $path     Request path (npr. /api/multitenant.php/fiskalizacija)
     * @param string $body     Request body (prazan string za GET)
     * @param int    $timestamp Unix timestamp
     */
    public function sign(string $method, string $path, string $body, int $timestamp): string
    {
        // Server koristi samo path bez query stringa (parse_url PHP_URL_PATH).
        $pathOnly = parse_url($path, PHP_URL_PATH) ?: $path;
        $bodyHash = hash('sha256', $body);
        $stringToSign = $timestamp . strtoupper($method) . $pathOnly . $bodyHash;

        return base64_encode(
            hash_hmac('sha256', $stringToSign, $this->apiSecret, true)
        );
    }

    /**
     * Generiše sve potrebne auth header-e za potpis v1 (kao u 1.0.2).
     *
     * @return array<string, string> Asocijativni niz header-a
     */
    public function getAuthHeaders(
        string $apiKey,
        string $method,
        string $path,
        string $body,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();
        $signature = $this->sign($method, $path, $body, $timestamp);

        return [
            'X-API-Key' => $apiKey,
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $signature,
        ];
    }

    /**
     * Generiše HMAC-SHA256 potpis v2 (query string ulazi u potpis).
     *
     * @param string $method   HTTP metod (GET, POST)
     * @param string $target   Request-target tačno kako se šalje: putanja sa
     *                         query-jem (npr. /api/multitenant.php/pdf?pfr=X&download=1)
     * @param string $body     Request body (prazan string za GET)
     * @param int    $timestamp Unix timestamp
     */
    public function signV2(string $method, string $target, string $body, int $timestamp): string
    {
        $bodyHash = hash('sha256', $body);
        $stringToSign = self::V2_PREFIX . $timestamp . strtoupper($method) . $target . $bodyHash;

        return base64_encode(
            hash_hmac('sha256', $stringToSign, $this->apiSecret, true)
        );
    }

    /**
     * Generiše sve potrebne auth header-e za potpis v2, uključujući
     * `X-Signature-Version: 2`.
     *
     * @param string $target Request-target tačno kako se šalje (putanja sa query-jem)
     * @return array<string, string> Asocijativni niz header-a
     */
    public function getAuthHeadersV2(
        string $apiKey,
        string $method,
        string $target,
        string $body,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();

        return [
            'X-API-Key' => $apiKey,
            'X-Timestamp' => (string) $timestamp,
            'X-Signature' => $this->signV2($method, $target, $body, $timestamp),
            'X-Signature-Version' => self::SIGNATURE_VERSION,
        ];
    }
}
