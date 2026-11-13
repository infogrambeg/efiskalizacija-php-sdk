<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\EfiskalizacijaClient;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Http\CurlHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Potpisani cilj je bajt-identičan onome što PRAVI {@see CurlHttpClient}
 * pošalje: SDK šalje zahteve na lokalni `php -S` sa routerom
 * `tests/Fixtures/zapisnik-router.php`, koji beleži sirov `REQUEST_URI`,
 * zaglavlja i telo. Potpis iz primljenih zaglavlja mora biti v2 potpis nad
 * PRIMLJENIM ciljem i telom — isto ono što server radi nad `REQUEST_URI`.
 *
 * @trace R3
 */
final class CurlPotpisaniCiljTest extends TestCase
{
    private const KLJUC = 'efisk_9_curl';
    private const TAJNA = 'tajna-curl-0123456789abcdef0123456789abcdef';

    /** @var resource|null */
    private $server = null;

    private int $port = 0;

    private string $zapisnik = '';

    protected function setUp(): void
    {
        if (!function_exists('curl_init')) {
            self::fail('ext-curl je obavezan (composer.json), a nije ucitan');
        }
        $this->zapisnik = sys_get_temp_dir() . '/efisk-sdk-zapisnik-' . bin2hex(random_bytes(6)) . '.jsonl';
        $this->port = self::slobodanPort();
        $okruzenje = ['EFISK_ZAPISNIK' => $this->zapisnik, 'PATH' => (string) getenv('PATH')];
        // Bez PHP_CLI_SERVER_WORKERS: jedan proces, pa ga proc_terminate stvarno gasi.
        $proces = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, dirname(__DIR__) . '/Fixtures/zapisnik-router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $cevi,
            null,
            $okruzenje,
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

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        if (is_file($this->zapisnik)) {
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

    /** @return list<array{method: string, uri: string, headers: array<string, string>, body: string}> */
    private function primljeno(): array
    {
        $zapisi = [];
        $redovi = is_file($this->zapisnik) ? file($this->zapisnik, FILE_IGNORE_NEW_LINES) : [];
        foreach ($redovi ?: [] as $red) {
            $z = json_decode($red, true, 8, JSON_THROW_ON_ERROR);
            $zapisi[] = [
                'method' => $z['method'],
                'uri' => (string) base64_decode($z['uri'], true),
                'headers' => array_map(static fn (string $v): string => (string) base64_decode($v, true), $z['headers']),
                'body' => (string) base64_decode($z['body'], true),
            ];
        }

        return $zapisi;
    }

    private function klijent(string $putanjaBaze = ''): EfiskalizacijaClient
    {
        return new EfiskalizacijaClient(new Config(
            self::KLJUC,
            self::TAJNA,
            'http://127.0.0.1:' . $this->port . $putanjaBaze,
            timeout: 5,
            connectTimeout: 2,
            maxRetries: 0,
        ));
    }

    /** Zaglavlje iz `getallheaders()` bez obzira na velika i mala slova imena. */
    private static function zaglavlje(array $zaglavlja, string $ime): ?string
    {
        foreach ($zaglavlja as $k => $v) {
            if (strcasecmp((string) $k, $ime) === 0) {
                return $v;
            }
        }

        return null;
    }

    /**
     * Poziv, putanja baze, metoda, očekivan sirov `REQUEST_URI`, prefiks koji
     * gateway skida pre servera ('' = server na korenu hosta).
     *
     * @return iterable<string, array{callable(EfiskalizacijaClient): mixed, string, string, string, string}>
     */
    public static function pozivi(): iterable
    {
        yield 'GET /status' => [static fn (EfiskalizacijaClient $c) => $c->status()->fetch(), '', 'GET', '/api/multitenant.php/status', ''];
        yield 'GET /invoice limit i offset' => [static fn (EfiskalizacijaClient $c) => $c->invoices()->list(10, 20), '', 'GET', '/api/multitenant.php/invoice?limit=10&offset=20', ''];
        yield 'GET /pdf sa download=1' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('ZE4GVYYT-ABCD-1', true), '', 'GET', '/api/multitenant.php/pdf?pfr=ZE4GVYYT-ABCD-1&download=1', ''];
        yield 'GET /pdf: razmak, +, /, Ž, [] i & u vrednosti' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('A B+C/Ž[]&x=1', true), '', 'GET', '/api/multitenant.php/pdf?pfr=A%20B%2BC%2F%C5%BD%5B%5D%26x%3D1&download=1', ''];
        yield 'GET /pdf sa ~' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('A~B', true), '', 'GET', '/api/multitenant.php/pdf?pfr=A~B&download=1', ''];
        yield 'GET sa [] u imenu parametra' => [static fn (EfiskalizacijaClient $c) => self::sirovApi($c)->sirovGet('/invoice', ['f' => ['a b', 'Ž'], 'g' => 'x&y=z']), '', 'GET', '/api/multitenant.php/invoice?f%5B0%5D=a%20b&f%5B1%5D=%C5%BD&g=x%26y%3Dz', ''];
        yield 'GET sa praznim query-jem (bez ?)' => [static fn (EfiskalizacijaClient $c) => self::sirovApi($c)->sirovGet('/invoice', ['a' => null]), '', 'GET', '/api/multitenant.php/invoice', ''];
        // Bazni URL sa putanjom radi samo iza gateway-a koji skida prefiks:
        // potpisan je cilj bez prefiksa, onaj koji server iza gateway-a vidi
        yield 'GET kroz bazni URL sa putanjom' => [static fn (EfiskalizacijaClient $c) => $c->invoices()->list(5, 0), '/pod', 'GET', '/pod/api/multitenant.php/invoice?limit=5&offset=0', '/pod'];
        yield 'POST /fiskalizacija' => [static fn (EfiskalizacijaClient $c) => $c->fiskalizacija()->fiskalizuj(
            Invoice::create()->setPaymentType(PaymentType::Kartica)->addItem(new InvoiceItem(naziv: 'Čaša Ž', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20))
        ), '', 'POST', '/api/multitenant.php/fiskalizacija', ''];
    }

    /** Pravi AbstractApi sa javnim `getRaw` (za parametre koje javne metode ne grade). */
    private static function sirovApi(EfiskalizacijaClient $c): object
    {
        $config = $c->getConfig();

        return new class ($config, new CurlHttpClient(5, 2), new HmacSigner($config->getApiSecret())) extends AbstractApi {
            /** @param array<string, mixed> $query */
            public function sirovGet(string $endpoint, array $query): string
            {
                return $this->getRaw($endpoint, $query)->body;
            }
        };
    }

    /**
     * @param callable(EfiskalizacijaClient): mixed $poziv
     */
    #[DataProvider('pozivi')]
    public function testPotpisaniCiljJePrimljeniCilj(callable $poziv, string $putanjaBaze, string $metoda, string $ocekivanCilj, string $prefiksGatewaya): void
    {
        $poziv($this->klijent($putanjaBaze));

        $primljeno = $this->primljeno();
        self::assertCount(1, $primljeno);
        $z = $primljeno[0];
        self::assertSame($metoda, $z['method']);
        self::assertSame($ocekivanCilj, $z['uri'], 'sirov REQUEST_URI');
        if ($metoda === 'POST') {
            self::assertStringContainsString('"naziv":"Čaša Ž"', $z['body']);
        } else {
            self::assertSame('', $z['body']);
        }

        $ts = (string) self::zaglavlje($z['headers'], 'X-Timestamp');
        self::assertSame(self::KLJUC, self::zaglavlje($z['headers'], 'X-API-Key'));
        self::assertSame('2', self::zaglavlje($z['headers'], 'X-Signature-Version'));
        // Server vidi REQUEST_URI posle gateway-a (bez prefiksa koji gateway skida)
        self::assertStringStartsWith($prefiksGatewaya . '/api/multitenant.php/', $z['uri']);
        $serverskiCilj = substr($z['uri'], strlen($prefiksGatewaya));
        $potpis = static fn (string $cilj): string => base64_encode(hash_hmac('sha256', 'v2:' . $ts . $metoda . $cilj . hash('sha256', $z['body']), self::TAJNA, true));
        // Server: v2 potpis nad tim REQUEST_URI i primljenim telom
        self::assertSame($potpis($serverskiCilj), self::zaglavlje($z['headers'], 'X-Signature'));
        if ($prefiksGatewaya !== '') {
            self::assertNotSame($potpis($z['uri']), self::zaglavlje($z['headers'], 'X-Signature'), 'prefiks baze se ne potpisuje');
        }
    }

    // ========== serijalizacija klijenta (radi u 1.0.2) ==========

    /** Svaki primljen zahtev nosi ispravan v2 potpis nad primljenim ciljem. */
    private function sviPrimljeniSuV2(int $broj): void
    {
        $primljeno = $this->primljeno();
        self::assertCount($broj, $primljeno);
        foreach ($primljeno as $z) {
            $ts = (string) self::zaglavlje($z['headers'], 'X-Timestamp');
            self::assertSame('2', self::zaglavlje($z['headers'], 'X-Signature-Version'));
            self::assertSame(
                base64_encode(hash_hmac('sha256', 'v2:' . $ts . $z['method'] . $z['uri'] . hash('sha256', $z['body']), self::TAJNA, true)),
                self::zaglavlje($z['headers'], 'X-Signature'),
            );
        }
    }

    /**
     * `serialize()` klijenta posle poziva (Api objekat već postoji) radi kao
     * u 1.0.2, a deserijalizovan klijent šalje ispravno potpisane zahteve.
     *
     * @trace R1
     */
    public function testKlijentSeSerijalizujePosleZahteva(): void
    {
        $klijent = $this->klijent();
        $klijent->status()->fetch();

        $kopija = unserialize(serialize($klijent));
        self::assertInstanceOf(EfiskalizacijaClient::class, $kopija);
        $kopija->status()->fetch();
        $kopija->invoices()->list(3, 0);

        $this->sviPrimljeniSuV2(3);
    }

    /**
     * Klijent serijalizovan u 1.0.2 nema svojstva `$clock` i `$sleep`
     * (`AbstractApi`). Oblik se dobija iz serijalizacije 1.1.0 uklanjanjem ta
     * dva svojstva; deserijalizovan, i dalje šalje zahtev.
     *
     * @trace R1
     */
    public function testKlijentSerijalizovanBezSataIPauzeSaljeZahtev(): void
    {
        $klijent = $this->klijent();
        $klijent->status();
        $s = serialize($klijent);

        foreach (['clock', 'sleep'] as $ime) {
            $zapis = serialize("\0" . AbstractApi::class . "\0" . $ime) . 'N;';
            // Pozitivna kontrola: svojstvo je u serijalizaciji tačno jednom, kao null
            self::assertSame(1, substr_count($s, $zapis), $ime);
            $s = str_replace($zapis, '', $s);
        }
        $glava = 'O:' . strlen(\Efiskalizacija\Api\StatusApi::class) . ':"' . \Efiskalizacija\Api\StatusApi::class . '":';
        $od = strpos($s, $glava);
        self::assertNotFalse($od);
        $od += strlen($glava);
        $do = (int) strpos($s, ':', $od);
        $broj = (int) substr($s, $od, $do - $od);
        $s = substr($s, 0, $od) . ($broj - 2) . substr($s, $do);

        $kopija = unserialize($s);
        self::assertInstanceOf(EfiskalizacijaClient::class, $kopija);
        $kopija->status()->fetch();

        $this->sviPrimljeniSuV2(1);
    }
}
