<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Http\CurlHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * PRAVI {@see CurlHttpClient} ne emituje E_DEPRECATED (na PHP 8.5 je
 * `curl_close()` zastareo, a od PHP 8.0 nema efekta) — ni kad zahtev uspe
 * (lokalni `php -S` sa routerom `tests/Fixtures/zapisnik-router.php`), ni u
 * grani cURL greške (port na kome niko ne sluša).
 *
 * @trace R1
 */
final class CurlBezZastarelogPozivaTest extends TestCase
{
    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $zapisnik = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if ($this->zapisnik !== '' && is_file($this->zapisnik)) {
            unlink($this->zapisnik);
        }
    }

    private static function slobodanPort(): int
    {
        $soket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertNotFalse($soket, 'slobodan port: ' . $errstr);
        $ime = (string) stream_socket_get_name($soket, false);
        fclose($soket);

        return (int) substr($ime, (int) strrpos($ime, ':') + 1);
    }

    private function pokreniServer(): void
    {
        $this->zapisnik = sys_get_temp_dir() . '/efisk-sdk-curl-' . bin2hex(random_bytes(6)) . '.jsonl';
        $this->port = self::slobodanPort();
        $proces = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, dirname(__DIR__) . '/Fixtures/zapisnik-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $cevi,
            null,
            ['EFISK_ZAPISNIK' => $this->zapisnik, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($proces, 'php -S nije pokrenut');
        $this->server = $proces;

        for ($i = 0; $i < 100; $i++) {
            $veza = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if ($veza !== false) {
                fclose($veza);

                return;
            }
            usleep(50000);
        }
        self::fail('php -S ne prima veze na portu ' . $this->port);
    }

    /**
     * Izvrši $poziv uz sopstveni rukovalac grešaka i vrati sve uhvaćene
     * E_DEPRECATED poruke. Rukovalac PHPUnit-a se vraća u `finally`.
     *
     * @param callable(): void $poziv
     * @return list<string>
     */
    private static function zastareloTokom(callable $poziv): array
    {
        $uhvaceno = [];
        $staroReporting = error_reporting(E_ALL);
        set_error_handler(static function (int $nivo, string $poruka, string $fajl, int $red) use (&$uhvaceno): bool {
            if ($nivo === E_DEPRECATED || $nivo === E_USER_DEPRECATED) {
                $uhvaceno[] = $poruka . ' @ ' . basename($fajl) . ':' . $red;
            }

            return true;
        });
        try {
            $poziv();
        } finally {
            restore_error_handler();
            error_reporting($staroReporting);
        }

        return $uhvaceno;
    }

    /**
     * Pozitivna kontrola: rukovalac stvarno hvata E_DEPRECATED iz ugrađene
     * funkcije. Na PHP < 8.5 `curl_close()` nije zastareo, pa kontrola nema
     * izvor i preskače se.
     */
    public function testRukovalacHvataZastareloIzUgradjeneFunkcije(): void
    {
        if (PHP_VERSION_ID < 80500) {
            self::assertSame([], self::zastareloTokom(static function (): void {
                $ch = curl_init();
                curl_close($ch);
            }));

            return;
        }

        $uhvaceno = self::zastareloTokom(static function (): void {
            $ch = curl_init();
            curl_close($ch);
        });

        self::assertCount(1, $uhvaceno);
        self::assertStringContainsString('curl_close', $uhvaceno[0]);
    }

    public function testUspesanZahtevBezZastarelog(): void
    {
        $this->pokreniServer();
        $odgovor = null;

        $uhvaceno = self::zastareloTokom(function () use (&$odgovor): void {
            $odgovor = (new CurlHttpClient(5, 2))->request('GET', 'http://127.0.0.1:' . $this->port . '/api/multitenant.php/status');
        });

        self::assertSame([], $uhvaceno);
        self::assertNotNull($odgovor);
        self::assertSame(200, $odgovor->statusCode);
        self::assertSame('{"success":true,"data":{}}', $odgovor->body);
        self::assertSame(1, count((array) file($this->zapisnik, FILE_IGNORE_NEW_LINES)), 'server je primio zahtev');
    }

    public function testPostZahtevBezZastarelog(): void
    {
        $this->pokreniServer();
        $odgovor = null;

        $uhvaceno = self::zastareloTokom(function () use (&$odgovor): void {
            $odgovor = (new CurlHttpClient(5, 2))->request('POST', 'http://127.0.0.1:' . $this->port . '/api/multitenant.php/test', [], '{"a":1}');
        });

        self::assertSame([], $uhvaceno);
        self::assertNotNull($odgovor);
        self::assertSame(200, $odgovor->statusCode);
    }

    public function testCurlGreskaBezZastarelog(): void
    {
        // Port je oslobođen odmah po dobijanju: na njemu niko ne sluša
        $port = self::slobodanPort();
        $izuzetak = null;

        $uhvaceno = self::zastareloTokom(static function () use ($port, &$izuzetak): void {
            try {
                (new CurlHttpClient(5, 2))->request('GET', 'http://127.0.0.1:' . $port . '/api/multitenant.php/status');
            } catch (NetworkException $e) {
                $izuzetak = $e;
            }
        });

        self::assertSame([], $uhvaceno);
        self::assertInstanceOf(NetworkException::class, $izuzetak, 'grana cURL greške');
        self::assertStringStartsWith('cURL greska (', $izuzetak->getMessage());
        self::assertGreaterThan(0, $izuzetak->getCode());
    }

    /** Važi i na PHP < 8.5, gde poziv ne daje E_DEPRECATED. */
    public function testIzvorNePozivaCurlClose(): void
    {
        $izvor = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Http/CurlHttpClient.php');
        self::assertStringContainsString('curl_exec(', $izvor, 'pozitivna kontrola: čita se pravi fajl');
        self::assertSame(0, preg_match('/\bcurl_close\s*\(/', $izvor));
    }
}
