<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Config;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\EfiskalizacijaClient;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Exception\EfiskalizacijaException;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Http\CurlHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * `NetworkException::wasRequestSent()` iz PRAVOG cURL-a: `false` samo kad
 * cURL nije poslao nijedan bajt zahteva (`CURLINFO_REQUEST_SIZE === 0`),
 * inače `true`.
 *
 * Izmereno (cURL 8.23.0, PHP 8.5, macOS): zatvoren port → errno 7, 0 B;
 * nerazrešiv host → errno 6, 0 B; server koji primi konekciju i ćuti →
 * errno 28 posle `CURLOPT_TIMEOUT` = 1 s, 160 B (POST), 126 B (GET).
 *
 * Server koji „primi i ćuti" je socket na kome se `accept()` ne zove: TCP
 * vezu uspostavlja kernel, cURL šalje zahtev u bafer, odgovor ne stiže. Broj
 * uspostavljenih veza se čita posle poziva (`accept` sa rokom 0).
 *
 * @trace R3
 */
final class CurlSaznanjeOSlanjuTest extends TestCase
{
    /**
     * Promenljive okruženja koje libcurl čita za proxy (`http_proxy` samo
     * malim slovima; ostale u oba oblika). Sa proxy-jem bi zahtev ka
     * 127.0.0.1 ili `.invalid` otišao proxy-ju, pa bi errno i broj poslatih
     * bajtova merili proxy, a ne server.
     */
    private const PROXY_PROMENLJIVE = [
        'http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY',
        'all_proxy', 'ALL_PROXY', 'no_proxy', 'NO_PROXY',
    ];

    /** @var list<resource> */
    private array $serveri = [];

    /** @var array<string, string|false> Vrednosti pre testa (false = nije postavljena) */
    private array $proxyPre = [];

    /**
     * Proxy promenljive se brišu iz okruženja procesa (`putenv`, koje libcurl
     * čita pri svakom prenosu), a `NO_PROXY=*` isključuje proxy i ako ga neko
     * postavi drugim putem; `tearDown()` vraća zatečeno stanje.
     */
    protected function setUp(): void
    {
        foreach (self::PROXY_PROMENLJIVE as $ime) {
            $this->proxyPre[$ime] = getenv($ime, true);
            putenv($ime);
        }
        putenv('NO_PROXY=*');
        putenv('no_proxy=*');
    }

    protected function tearDown(): void
    {
        foreach ($this->serveri as $s) {
            fclose($s);
        }
        $this->serveri = [];

        foreach ($this->proxyPre as $ime => $vrednost) {
            putenv($vrednost === false ? $ime : "{$ime}={$vrednost}");
        }
        $this->proxyPre = [];
    }

    /** Kontrola izolacije: proxy promenljive su uklonjene, `NO_PROXY=*`. */
    public function testOkruzenjeJeBezProxyja(): void
    {
        foreach (['http_proxy', 'HTTP_PROXY', 'https_proxy', 'HTTPS_PROXY', 'all_proxy', 'ALL_PROXY'] as $ime) {
            self::assertFalse(getenv($ime, true), $ime);
        }
        self::assertSame('*', getenv('NO_PROXY', true));
        self::assertSame('*', getenv('no_proxy', true));
    }

    /** Adresa porta koji je bio slobodan i sada je zatvoren. */
    private static function zatvorenPort(): string
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($s, $errstr);
        $adresa = (string) stream_socket_get_name($s, false);
        fclose($s);

        return 'http://' . $adresa;
    }

    /** Adresa servera koji prima veze i nikad ne odgovara. */
    private function serverKojiCuti(): array
    {
        $s = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($s, $errstr);
        $this->serveri[] = $s;

        return ['url' => 'http://' . stream_socket_get_name($s, false), 'socket' => $s];
    }

    /** @param resource $s */
    private static function brojVeza($s): int
    {
        $n = 0;
        while (($veza = @stream_socket_accept($s, 0)) !== false) {
            $n++;
            fclose($veza);
        }

        return $n;
    }

    private static function izuzetak(callable $poziv): NetworkException
    {
        try {
            $poziv();
        } catch (NetworkException $e) {
            return $e;
        }
        self::fail('očekivan NetworkException');
    }

    public function testZatvorenPortNijePoslat(): void
    {
        $url = self::zatvorenPort() . '/api/multitenant.php/fiskalizacija';
        foreach (['POST', 'GET'] as $metoda) {
            $e = self::izuzetak(static fn () => (new CurlHttpClient(1, 1))->request($metoda, $url, ['X-API-Key' => 'k'], $metoda === 'POST' ? '{"a":1}' : ''));

            self::assertSame(7, $e->getCode(), "{$metoda}: CURLE_COULDNT_CONNECT");
            self::assertFalse($e->wasRequestSent(), $metoda);
        }
    }

    public function testNerazresivHostNijePoslat(): void
    {
        $host = 'nepostojeci-host.invalid';
        // `.invalid` ne sme da se razreši (RFC 6761), ali resolver koji sve
        // preusmerava (npr. DNS hvatač provajdera) ga ipak razreši: tada test
        // ne meri ono što tvrdi.
        if (gethostbyname($host) !== $host) {
            self::markTestSkipped("DNS razrešava {$host}");
        }
        foreach (['http', 'https'] as $sema) {
            $e = self::izuzetak(static fn () => (new CurlHttpClient(1, 1))->request('POST', "{$sema}://{$host}/api/multitenant.php/fiskalizacija", [], '{"a":1}'));

            self::assertSame(6, $e->getCode(), "{$sema}: CURLE_COULDNT_RESOLVE_HOST");
            self::assertFalse($e->wasRequestSent(), $sema);
        }
    }

    public function testServerKojiPrimiICutiJePoslat(): void
    {
        foreach (['POST', 'GET'] as $metoda) {
            ['url' => $url, 'socket' => $s] = $this->serverKojiCuti();

            $e = self::izuzetak(static fn () => (new CurlHttpClient(1, 1))->request($metoda, $url . '/api/multitenant.php/fiskalizacija', ['X-API-Key' => 'k'], $metoda === 'POST' ? '{"a":1}' : ''));

            self::assertSame(28, $e->getCode(), "{$metoda}: CURLE_OPERATION_TIMEDOUT");
            self::assertTrue($e->wasRequestSent(), $metoda);
            self::assertSame(1, self::brojVeza($s), "{$metoda}: zahtev je stigao do servera");
        }
    }

    public function testKonstruktorJeKompatibilanSa111(): void
    {
        $stari = new NetworkException('timeout', 28);
        self::assertNull($stari->wasRequestSent(), 'podrazumevano: nepoznato');
        self::assertSame('timeout', $stari->getMessage());
        self::assertSame(28, $stari->getCode());

        $prethodni = new \RuntimeException('uzrok');
        $pun = new NetworkException('m', 6, $prethodni, 'telo', false);
        self::assertSame($prethodni, $pun->getPrevious());
        self::assertSame('telo', $pun->getResponseBody());
        self::assertFalse($pun->wasRequestSent());
        self::assertTrue((new NetworkException(requestSent: true))->wasRequestSent());
        self::assertInstanceOf(EfiskalizacijaException::class, $pun);
    }

    /**
     * Izuzetak iz 1.1.1 (bez svojstva `requestSent`) posle `unserialize()`
     * daje null, ne grešku neinicijalizovanog svojstva.
     */
    public function testDeserijalizovanIzuzetakBezSvojstvaJeNepoznat(): void
    {
        $serijalizovan = serialize(new NetworkException('timeout', 28));
        $bezSvojstva = (string) preg_replace(
            '/s:\d+:"\x00Efiskalizacija\\\\Exception\\\\NetworkException\x00requestSent";N;/',
            '',
            $serijalizovan,
            -1,
            $zamena,
        );
        self::assertSame(1, $zamena, 'svojstvo uklonjeno iz zapisa');
        $bezSvojstva = (string) preg_replace_callback(
            '/^O:(\d+):"([^"]+)":(\d+):/',
            static fn (array $m): string => 'O:' . $m[1] . ':"' . $m[2] . '":' . ((int) $m[3] - 1) . ':',
            $bezSvojstva,
        );

        $e = unserialize($bezSvojstva);
        self::assertInstanceOf(NetworkException::class, $e);
        self::assertNull($e->wasRequestSent());
    }

    private static function racun(?string $broj): Invoice
    {
        $racun = Invoice::create()
            ->setPaymentType(PaymentType::Kartica)
            ->addItem(new InvoiceItem(naziv: 'Test', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20));

        return $broj === null ? $racun : $racun->setInvoiceNumber($broj);
    }

    private static function klijent(string $baseUrl, int $maxRetries): EfiskalizacijaClient
    {
        return new EfiskalizacijaClient(
            new Config('efisk_1_test', 'tajna', $baseUrl, timeout: 1, connectTimeout: 1, maxRetries: $maxRetries, retryBaseDelayMs: 0),
            new CurlHttpClient(1, 1),
        );
    }

    /**
     * Od kraja do kraja kroz pravi cURL: fiskalizacija BEZ broja ka serveru
     * koji primi zahtev i ćuti stiže do servera tačno jednom; sa brojem se
     * ponavlja (server je prepoznaje po broju).
     */
    public function testRacunBezBrojaKaServeruKojiCutiIdeJednom(): void
    {
        ['url' => $url, 'socket' => $s] = $this->serverKojiCuti();
        $e = self::izuzetak(static fn () => self::klijent($url, 2)->fiskalizacija()->fiskalizuj(self::racun(null)));
        self::assertTrue($e->wasRequestSent());
        self::assertSame(1, self::brojVeza($s), 'bez broja: tačno jedan zahtev');

        ['url' => $url, 'socket' => $s] = $this->serverKojiCuti();
        self::izuzetak(static fn () => self::klijent($url, 1)->fiskalizacija()->fiskalizuj(self::racun('SHOP-1')));
        self::assertSame(2, self::brojVeza($s), 'sa brojem: početni zahtev + 1 ponavljanje');
    }

    /**
     * Zatvoren port: zahtev sigurno nije poslat, pa se i račun bez broja
     * ponavlja (1 + maxRetries pokušaja, svaki sa `wasRequestSent() === false`).
     */
    public function testRacunBezBrojaKaZatvorenomPortuSePonavlja(): void
    {
        $url = self::zatvorenPort();
        $brojac = new \stdClass();
        $brojac->pokusaja = 0;
        $logger = new class ($brojac) extends \Psr\Log\AbstractLogger {
            public function __construct(private \stdClass $brojac)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ($message === 'API zahtev') {
                    $this->brojac->pokusaja++;
                }
            }
        };
        $config = new Config('efisk_1_test', 'tajna', $url, timeout: 1, connectTimeout: 1, maxRetries: 2, retryBaseDelayMs: 0);
        $api = new \Efiskalizacija\Api\FiskalizacijaApi($config, new CurlHttpClient(1, 1), new \Efiskalizacija\Auth\HmacSigner('tajna'), $logger);

        $e = self::izuzetak(static fn () => $api->fiskalizuj(self::racun(null)));

        self::assertFalse($e->wasRequestSent());
        self::assertSame(3, $brojac->pokusaja, 'početni zahtev + 2 ponavljanja');
    }
}
