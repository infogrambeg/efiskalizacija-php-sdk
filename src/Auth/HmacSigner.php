<?php

declare(strict_types=1);

namespace Efiskalizacija\Auth;

/**
 * HMAC-SHA256 potpis za eFiskalizacija API.
 *
 * Algoritam je 1:1 sa serverskim HmacAuthenticator::generateSignature():
 *   1. bodyHash = SHA256(requestBody)
 *   2. stringToSign = "{timestamp}{METHOD}{path}{bodyHash}"
 *   3. signature = Base64(HMAC-SHA256(apiSecret, stringToSign))
 */
final class HmacSigner
{
    private string $apiSecret;

    public function __construct(string $apiSecret)
    {
        $this->apiSecret = $apiSecret;
    }

    /**
     * Generiše HMAC-SHA256 potpis.
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
     * Generiše sve potrebne auth header-e.
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
}
