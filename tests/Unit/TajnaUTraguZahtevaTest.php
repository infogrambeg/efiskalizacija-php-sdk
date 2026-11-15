<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Api\EmailApi;
use Efiskalizacija\Api\FiskalizacijaApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Http\CurlHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Trag izuzetka iz slanja zahteva (`NetworkException` iz pravog
 * `CurlHttpClient`) ne nosi API ključ, potpis ni telo zahteva: parametri duž
 * putanje od javnih metoda do `CurlHttpClient::request` nose
 * `#[\SensitiveParameter]`.
 *
 * Proverava se u POD-PROCESU sa `zend.exception_ignore_args=0` i najvećim
 * `zend.exception_string_param_max_len` (ini PHPUnit procesa ili php.ini sa
 * `ignore_args=On` bi sam sakrio argumente i učinio test slepim). Pozitivne
 * kontrole: funkcija bez atributa sa istim vrednostima pokazuje sve tri, a
 * svaki slučaj mora imati okvir `CurlHttpClient::request` sa 4 argumenta.
 *
 * @trace R4
 */
final class TajnaUTraguZahtevaTest extends TestCase
{
    /** @var array{ini_ignore_args: string, php: int, slucajevi: array<string, array<string, mixed>>}|null */
    private static ?array $rezultat = null;

    /** @return array{ini_ignore_args: string, php: int, slucajevi: array<string, array<string, mixed>>} */
    private static function rezultat(): array
    {
        if (self::$rezultat === null) {
            ['izlaz' => $izlaz, 'greske' => $greske, 'kod' => $kod] = PodProces::izvrsi([
                PHP_BINARY,
                '-d', 'zend.exception_ignore_args=0',
                '-d', 'zend.exception_string_param_max_len=1000000',
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=E_ALL',
                dirname(__DIR__) . '/Fixtures/tajna-u-tragu-zahteva.php',
            ]);
            self::assertSame('', $greske, 'stderr pod-procesa');
            self::assertSame(0, $kod, 'izlaz pod-procesa: ' . $izlaz);
            self::$rezultat = json_decode($izlaz, true, 16, JSON_THROW_ON_ERROR);
        }

        return self::$rezultat;
    }

    public function testPozitivnaKontrolaVidiKljucPotpisITelo(): void
    {
        $r = self::rezultat();

        self::assertSame('0', $r['ini_ignore_args']);
        self::assertSame(PHP_VERSION_ID, $r['php']);
        $k = $r['slucajevi']['kontrola'];
        self::assertTrue($k['baceno']);
        foreach (['kljuc_u_tragu', 'potpis_u_tragu', 'telo_u_tragu', 'telo_u_tekstu'] as $polje) {
            self::assertTrue($k[$polje], "kontrola: {$polje}");
        }
    }

    /**
     * Očekivano u tragu (uz `zend.exception_ignore_args=0`): na PHP ≥ 8.2
     * atribut krije sve; na PHP 8.1 atribut nema efekta, pa se ključ i potpis
     * (zaglavlja u argumentu `request()`) i telo vide — dokumentovano u README.
     *
     * @return array{kljuc: bool, potpis: bool, telo: bool}
     */
    public static function ocekivanoUTragu(int $phpVersionId, bool $imaTelo): array
    {
        if ($phpVersionId >= 80200) {
            return ['kljuc' => false, 'potpis' => false, 'telo' => false];
        }

        return ['kljuc' => true, 'potpis' => true, 'telo' => $imaTelo];
    }

    public function testOcekivanjePoVerzijiPhpa(): void
    {
        self::assertSame(['kljuc' => true, 'potpis' => true, 'telo' => true], self::ocekivanoUTragu(80100, true));
        self::assertSame(['kljuc' => true, 'potpis' => true, 'telo' => false], self::ocekivanoUTragu(80199, false));
        self::assertSame(['kljuc' => false, 'potpis' => false, 'telo' => false], self::ocekivanoUTragu(80200, true));
        self::assertSame(['kljuc' => false, 'potpis' => false, 'telo' => false], self::ocekivanoUTragu(80500, false));
    }

    /** @return iterable<string, array{string}> */
    public static function javneMetode(): iterable
    {
        foreach (['fiskalizuj', 'send', 'run', 'fetch', 'list', 'download', 'downloadToFile'] as $ime) {
            yield $ime => [$ime];
        }
    }

    #[DataProvider('javneMetode')]
    public function testTragIzuzetkaIzCurlaNeNosiKljucPotpisNiTelo(string $ime): void
    {
        $s = self::rezultat()['slucajevi'][$ime] ?? null;
        self::assertIsArray($s, "slučaj {$ime} nije pokrenut");
        self::assertTrue($s['baceno'], "{$ime}: mora baciti izuzetak");
        self::assertSame(NetworkException::class, $s['klasa'], $ime);
        self::assertSame(4, $s['okvir_request_args'], "{$ime}: trag ima okvir CurlHttpClient::request sa argumentima");

        $o = self::ocekivanoUTragu(PHP_VERSION_ID, $s['ima_telo']);
        self::assertSame($o['kljuc'], $s['kljuc_u_tragu'], "{$ime}: ključ u getTrace()");
        self::assertSame($o['potpis'], $s['potpis_u_tragu'], "{$ime}: potpis u getTrace()");
        self::assertSame($o['telo'], $s['telo_u_tragu'], "{$ime}: telo u getTrace()");
        // (string) $e prikazuje niz kao „Array": ključ i potpis su samo u nizu
        // zaglavlja, pa se tu ne vide ni bez atributa; telo je string i vidi se.
        self::assertFalse($s['kljuc_u_tekstu'], "{$ime}: ključ u (string) \$e");
        self::assertFalse($s['potpis_u_tekstu'], "{$ime}: potpis u (string) \$e");
        self::assertSame($o['telo'], $s['telo_u_tekstu'], "{$ime}: telo u (string) \$e");
    }

    /**
     * Parametri duž putanje zahteva koji nose ključ, potpis, zaglavlja ili
     * telo.
     *
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function osetljiviParametri(): iterable
    {
        foreach (
            [
                [FiskalizacijaApi::class, 'fiskalizuj', 'invoice'],
                [EmailApi::class, 'send', 'pfrBroj'],
                [EmailApi::class, 'send', 'email'],
                [AbstractApi::class, 'post', 'data'],
                [AbstractApi::class, 'requestWithRetry', 'body'],
                [AbstractApi::class, 'requestRawWithRetry', 'body'],
                [AbstractApi::class, 'isSafeToRepeat', 'body'],
                [CurlHttpClient::class, 'request', 'headers'],
                [CurlHttpClient::class, 'request', 'body'],
                [HmacSigner::class, 'sign', 'body'],
                [HmacSigner::class, 'signV2', 'body'],
                [HmacSigner::class, 'getAuthHeaders', 'apiKey'],
                [HmacSigner::class, 'getAuthHeaders', 'body'],
                [HmacSigner::class, 'getAuthHeadersV2', 'apiKey'],
                [HmacSigner::class, 'getAuthHeadersV2', 'body'],
            ] as [$klasa, $metoda, $parametar]
        ) {
            yield "{$klasa}::{$metoda} \${$parametar}" => [$klasa, $metoda, $parametar];
        }
    }

    /** @param class-string $klasa */
    #[DataProvider('osetljiviParametri')]
    public function testParametarNosiSensitiveParameter(string $klasa, string $metoda, string $parametar): void
    {
        $nadjen = null;
        foreach ((new \ReflectionMethod($klasa, $metoda))->getParameters() as $p) {
            if ($p->getName() === $parametar) {
                $nadjen = $p;
            }
        }

        self::assertNotNull($nadjen, "parametar \${$parametar} postoji");
        self::assertCount(1, $nadjen->getAttributes(\SensitiveParameter::class));
    }
}
