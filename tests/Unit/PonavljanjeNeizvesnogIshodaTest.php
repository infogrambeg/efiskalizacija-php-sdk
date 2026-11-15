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
use Efiskalizacija\Exception\EfiskalizacijaException;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Exception\ServerException;
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * SDK sam ne ponavlja zahtev koji je možda već obrađen.
 *
 * „Bezbedan za ponavljanje" je samo GET i `POST /fiskalizacija` čije telo nosi
 * `broj_racuna` koji posle `trim` nije prazan (server tada prepoznaje već
 * upisan račun sa istim brojem i vraća ga umesto novog). Nebezbedan zahtev se ponavlja samo kad je sigurno da
 * server nije počeo obradu: 429 (server ga vraća pre obrade) i
 * `NetworkException` sa `wasRequestSent() === false`. Na 502, 503, 504 i
 * mrežnu grešku posle koje se ne zna da li je zahtev stigao, izuzetak ide
 * odmah, bez pauze, uz WARNING bez tela, ključa i potpisa. 503 nije „pre
 * obrade": Apache (`mod_proxy_fcgi`) ga vraća i kad je PHP zahtev već
 * obradio (radnik prekinut, `request_terminate_timeout`).
 *
 * @trace R1 R2
 */
final class PonavljanjeNeizvesnogIshodaTest extends TestCase
{
    private const KLJUC = 'efisk_1_KLJUC-u-logu-ne-sme';
    private const TAJNA = 'tajna-za-test-ponavljanja';
    private const MARKER_TELA = 'MARKER-TELA-u-logu-ne-sme';

    /**
     * Api sa javnim `post`/`get`, satom i pauzom pod kontrolom testa i
     * loggerom koji pamti zapise.
     *
     * @param list<Response|NetworkException> $odgovori
     * @return array{api: AbstractApi, stanje: \stdClass}
     */
    private static function postavka(array $odgovori, int $maxRetries = 2): array
    {
        $stanje = new \stdClass();
        $stanje->zahtevi = [];
        $stanje->pauze = [];
        $stanje->log = [];

        $config = new Config(self::KLJUC, self::TAJNA, maxRetries: $maxRetries, retryBaseDelayMs: 1);
        $http = new class ($odgovori, $stanje) implements HttpClientInterface {
            /** @param list<Response|NetworkException> $odgovori */
            public function __construct(private array $odgovori, private \stdClass $stanje)
            {
            }

            public function request(string $method, string $url, array $headers = [], string $body = ''): Response
            {
                $this->stanje->zahtevi[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
                $sledeci = array_shift($this->odgovori) ?? new Response(200, '{"success":true,"data":{}}');
                if ($sledeci instanceof NetworkException) {
                    throw $sledeci;
                }

                return $sledeci;
            }
        };
        $logger = new class ($stanje) extends AbstractLogger {
            public function __construct(private \stdClass $stanje)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->stanje->log[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $api = new class (
            $config,
            $http,
            new HmacSigner(self::TAJNA),
            $logger,
            static fn (): int => 1_705_000_000,
            static function (int $mikrosekunde) use ($stanje): void {
                $stanje->pauze[] = $mikrosekunde;
            },
        ) extends AbstractApi {
            /**
             * @param array<string, mixed>|null $data
             * @return array<string, mixed>
             */
            public function javniPost(string $endpoint, ?array $data): array
            {
                return $this->post($endpoint, $data);
            }

            /** @return array<string, mixed> */
            public function javniGet(string $endpoint): array
            {
                return $this->get($endpoint);
            }
        };

        return ['api' => $api, 'stanje' => $stanje];
    }

    private static function ok(): Response
    {
        return new Response(200, '{"success":true,"data":{}}');
    }

    /** @return list<array{level: mixed, message: string, context: array<string, mixed>}> */
    private static function upozorenja(\stdClass $stanje): array
    {
        return array_values(array_filter($stanje->log, static fn (array $z): bool => $z['level'] === 'warning'));
    }

    /**
     * Telo i putanja: da li je POST bezbedan za ponavljanje (isto pravilo kao
     * serverski `ClientReferenceGuard::normalize`).
     *
     * @return iterable<string, array{string, array<string, mixed>|null, bool}>
     */
    public static function postovi(): iterable
    {
        yield 'fiskalizacija sa brojem' => ['/fiskalizacija', ['broj_racuna' => 'SHOP-1', 'napomena' => self::MARKER_TELA], true];
        yield 'fiskalizacija sa brojem uz razmake' => ['/fiskalizacija', ['broj_racuna' => '  SHOP-1  '], true];
        yield 'fiskalizacija sa brojem 0 (string)' => ['/fiskalizacija', ['broj_racuna' => '0'], true];
        yield 'fiskalizacija sa brojem 0 (int)' => ['/fiskalizacija', ['broj_racuna' => 0], true];
        yield 'fiskalizacija sa brojem 123 (int)' => ['/fiskalizacija', ['broj_racuna' => 123], true];
        yield 'fiskalizacija sa brojem 1.5 (float)' => ['/fiskalizacija', ['broj_racuna' => 1.5], true];
        yield 'fiskalizacija sa query-jem' => ['/fiskalizacija?x=1', ['broj_racuna' => 'SHOP-1'], true];
        yield 'fiskalizacija bez broja' => ['/fiskalizacija', ['napomena' => self::MARKER_TELA], false];
        yield 'fiskalizacija bez tela' => ['/fiskalizacija', null, false];
        yield 'fiskalizacija sa praznim brojem' => ['/fiskalizacija', ['broj_racuna' => ''], false];
        yield 'fiskalizacija sa brojem od razmaka' => ['/fiskalizacija', ['broj_racuna' => " \t\n "], false];
        yield 'fiskalizacija sa null brojem' => ['/fiskalizacija', ['broj_racuna' => null], false];
        yield 'fiskalizacija sa true brojem' => ['/fiskalizacija', ['broj_racuna' => true], false];
        yield 'fiskalizacija sa false brojem' => ['/fiskalizacija', ['broj_racuna' => false], false];
        yield 'fiskalizacija sa nizom kao brojem' => ['/fiskalizacija', ['broj_racuna' => ['SHOP-1']], false];
        yield 'fiskalizacija sa objektom kao brojem' => ['/fiskalizacija', ['broj_racuna' => ['a' => 'SHOP-1']], false];
        yield 'fiskalizacija, broj samo u ugnezdenom polju' => ['/fiskalizacija', ['kupac' => ['broj_racuna' => 'SHOP-1']], false];
        yield 'fiskalizacija sa kosom crtom na kraju' => ['/fiskalizacija/', ['broj_racuna' => 'SHOP-1'], false];
        yield 'Fiskalizacija (veliko slovo)' => ['/Fiskalizacija', ['broj_racuna' => 'SHOP-1'], false];
        yield 'fiskalizacija sa prefiksom' => ['/v2/fiskalizacija', ['broj_racuna' => 'SHOP-1'], false];
        yield 'send-email sa brojem' => ['/send-email', ['broj_racuna' => 'SHOP-1', 'email' => 'a@b.rs'], false];
        yield 'test' => ['/test', null, false];
        yield 'partial-advance sa brojem' => ['/partial-advance', ['broj_racuna' => 'SHOP-1'], false];
        yield 'e-faktura sa brojem' => ['/e-faktura', ['broj_racuna' => 'SHOP-1'], false];
    }

    /**
     * Mrežna greška posle koje se ne zna da li je zahtev stigao: bezbedan
     * POST se ponavlja kao u 1.1.1, nebezbedan ide tačno jednom.
     *
     * @param array<string, mixed>|null $data
     */
    #[DataProvider('postovi')]
    public function testNepoznatIshodPonavljaSamoBezbedanPost(string $putanja, ?array $data, bool $bezbedan): void
    {
        ['api' => $api, 'stanje' => $stanje] = self::postavka([new NetworkException('timeout', 28), self::ok()]);

        $izuzetak = null;
        try {
            $api->javniPost($putanja, $data);
        } catch (NetworkException $e) {
            $izuzetak = $e;
        }

        if ($bezbedan) {
            self::assertNull($izuzetak, 'bezbedan zahtev se ponavlja i uspeva');
            self::assertCount(2, $stanje->zahtevi);
            self::assertSame([1000], $stanje->pauze);
            self::assertSame($stanje->zahtevi[0]['body'], $stanje->zahtevi[1]['body'], 'isto telo');

            return;
        }
        self::assertNotNull($izuzetak, 'nebezbedan zahtev: izuzetak odmah');
        self::assertCount(1, $stanje->zahtevi, 'nebezbedan zahtev ide tačno jednom');
        self::assertSame([], $stanje->pauze, 'bez pauze');
        self::assertCount(1, self::upozorenja($stanje));
    }

    /** @return iterable<string, array{Response|NetworkException, class-string<EfiskalizacijaException>}> */
    public static function nepoznatiIshodi(): iterable
    {
        yield 'mrezna greska, slanje nepoznato' => [new NetworkException('timeout', 28), NetworkException::class];
        yield 'mrezna greska, zahtev poslat' => [new NetworkException('timeout', 28, requestSent: true), NetworkException::class];
        yield '502' => [new Response(502, '{"success":false,"error":"Bad Gateway"}'), ServerException::class];
        yield '503' => [new Response(503, '{"success":false,"error":"Unavailable"}'), ServerException::class];
        yield '503 iz Apache-a (text/html)' => [new Response(503, '<html><body>Service Unavailable</body></html>', ['Content-Type' => 'text/html']), ServerException::class];
        yield '504' => [new Response(504, '{"success":false,"error":"Gateway Timeout"}'), ServerException::class];
    }

    /**
     * Nebezbedan zahtev (POST `/fiskalizacija` bez broja, `/send-email`,
     * `/test`, POST iz podklase na drugu rutu) se ne ponavlja kad ishod nije poznat, ni
     * kad ostaju pokušaji; WARNING nosi samo metodu, putanju, pokušaj i
     * uzrok — bez tela, ključa i potpisa.
     *
     * @param class-string<EfiskalizacijaException> $klasa
     */
    #[DataProvider('nepoznatiIshodi')]
    public function testNebezbedanZahtevSeNePonavljaKadIshodNijePoznat(Response|NetworkException $ishod, string $klasa): void
    {
        foreach (
            [
                'fiskalizacija bez broja' => ['/fiskalizacija', ['napomena' => self::MARKER_TELA]],
                'send-email' => ['/send-email', ['pfr' => 'P-1', 'email' => self::MARKER_TELA . '@primer.rs']],
                'test' => ['/test', null],
                'podklasa' => ['/nesto?upit=' . rawurlencode(self::MARKER_TELA), ['x' => self::MARKER_TELA]],
            ] as $opis => [$putanja, $data]
        ) {
            ['api' => $api, 'stanje' => $stanje] = self::postavka([$ishod, self::ok(), self::ok()], maxRetries: 3);

            $izuzetak = null;
            try {
                $api->javniPost($putanja, $data);
            } catch (EfiskalizacijaException $e) {
                $izuzetak = $e;
            }

            self::assertInstanceOf($klasa, $izuzetak, $opis);
            self::assertCount(1, $stanje->zahtevi, "{$opis}: tačno jedan zahtev");
            self::assertSame([], $stanje->pauze, "{$opis}: bez pauze");

            $upozorenja = self::upozorenja($stanje);
            self::assertCount(1, $upozorenja, $opis);
            $kontekst = $upozorenja[0]['context'];
            self::assertSame('POST', $kontekst['method'] ?? null, $opis);
            self::assertSame(explode('?', $putanja, 2)[0], $kontekst['path'] ?? null, "{$opis}: putanja bez query-ja");
            self::assertSame(1, $kontekst['attempt'] ?? null, $opis);
            // Uzrok: klasa izuzetka za mrežnu granu, status za HTTP granu —
            // i ništa van belog spiska
            if ($ishod instanceof NetworkException) {
                self::assertSame(NetworkException::class, $kontekst['exception_class'] ?? null, "{$opis}: exception_class");
                $kljucevi = ['attempt', 'exception_class', 'method', 'path'];
            } else {
                self::assertSame($ishod->statusCode, $kontekst['status'] ?? null, "{$opis}: status");
                $kljucevi = ['attempt', 'method', 'path', 'status'];
            }
            $stvarni = array_keys($kontekst);
            sort($stvarni);
            self::assertSame($kljucevi, $stvarni, "{$opis}: ključevi konteksta");

            $zapis = json_encode($upozorenja, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $poslato = $stanje->zahtevi[0];
            self::assertStringNotContainsString(self::KLJUC, $zapis, "{$opis}: ključ");
            self::assertStringNotContainsString($poslato['headers']['X-Signature'], $zapis, "{$opis}: potpis");
            self::assertStringNotContainsString(self::MARKER_TELA, $zapis, "{$opis}: telo");
            if ($poslato['body'] !== '') {
                self::assertStringNotContainsString($poslato['body'], $zapis, "{$opis}: telo");
            }
            // Kontrola detektora: telo i potpis zaista nose ono što se traži
            self::assertStringContainsString(self::KLJUC, $poslato['headers']['X-API-Key']);
            if ($data !== null && $opis !== 'test') {
                self::assertStringContainsString(self::MARKER_TELA, $poslato['body'] . $poslato['url'], $opis);
            }
        }
    }

    /** @return iterable<string, array{Response|NetworkException}> */
    public static function ishodiPreObrade(): iterable
    {
        yield '429' => [new Response(429, '{"success":false,"error":"Rate limit"}')];
        yield 'mrezna greska, zahtev nije poslat' => [new NetworkException('cURL greska (7): odbijeno', 7, requestSent: false)];
    }

    /**
     * Server nije počeo obradu (429) ili zahtev nije ni poslat: i
     * nebezbedan zahtev se ponavlja sa pauzom, bez upozorenja o nepoznatom
     * ishodu.
     */
    #[DataProvider('ishodiPreObrade')]
    public function testNebezbedanZahtevSePonavljaKadServerNijePoceoObradu(Response|NetworkException $ishod): void
    {
        foreach (
            [
                'fiskalizacija bez broja' => ['/fiskalizacija', ['napomena' => 'x']],
                'send-email' => ['/send-email', ['pfr' => 'P-1', 'email' => 'a@b.rs']],
                'test' => ['/test', null],
                'podklasa' => ['/nesto', ['x' => 1]],
            ] as $opis => [$putanja, $data]
        ) {
            ['api' => $api, 'stanje' => $stanje] = self::postavka([$ishod, $ishod, self::ok()]);

            $api->javniPost($putanja, $data);

            self::assertCount(3, $stanje->zahtevi, $opis);
            self::assertSame([1000, 2000], $stanje->pauze, $opis);
            foreach (self::upozorenja($stanje) as $z) {
                self::assertStringNotContainsString('nije poznat', $z['message'], $opis);
            }
        }
    }

    /** @return iterable<string, array{Response|NetworkException, array<string, mixed>}> */
    public static function nepoznatIshodPosle429(): iterable
    {
        yield '429 pa 503' => [new Response(503, '{"success":false,"error":"Unavailable"}'), ['status' => 503]];
        yield '429 pa mrezna greska' => [new NetworkException('timeout', 28), ['exception_class' => NetworkException::class]];
    }

    /**
     * Nebezbedan zahtev se posle 429 ponavlja, a kad drugi pokušaj ima
     * nepoznat ishod, ide izuzetak odmah: WARNING nosi pokušaj u kome se to
     * desilo (2), ne prvi.
     *
     * @param array<string, mixed> $uzrok
     * @trace R2
     */
    #[DataProvider('nepoznatIshodPosle429')]
    public function testUpozorenjeNosiPokusajUKomeJeIshodNepoznat(Response|NetworkException $drugi, array $uzrok): void
    {
        ['api' => $api, 'stanje' => $stanje] = self::postavka(
            [new Response(429, '{"success":false,"error":"Rate limit"}'), $drugi, self::ok(), self::ok()],
            maxRetries: 3,
        );

        $izuzetak = null;
        try {
            $api->javniPost('/fiskalizacija', ['napomena' => 'bez broja']);
        } catch (EfiskalizacijaException $e) {
            $izuzetak = $e;
        }

        self::assertNotNull($izuzetak);
        self::assertCount(2, $stanje->zahtevi, 'početni zahtev + jedno ponavljanje posle 429');
        self::assertSame([1000], $stanje->pauze, 'pauza samo posle 429');
        $nepoznat = array_values(array_filter(
            self::upozorenja($stanje),
            static fn (array $z): bool => str_contains($z['message'], 'nije poznat'),
        ));
        self::assertCount(1, $nepoznat);
        self::assertSame(['method' => 'POST', 'path' => '/fiskalizacija', 'attempt' => 2] + $uzrok, $nepoznat[0]['context']);
    }

    /**
     * Izmereno: Apache `mod_proxy_fcgi` vraća 503 (text/html, bez
     * `Retry-After`) i kad je PHP zahtev već obradio. Nebezbedan POST posle
     * 503 zato ide tačno jednom: `ServerException` 503 odmah, bez pauze, uz
     * WARNING o nepoznatom ishodu — i kad server ponudi `Retry-After`.
     */
    public function testNebezbedanPostPosle503IdeTacnoJednom(): void
    {
        foreach (
            [
                'Apache text/html' => new Response(503, '<html><body>Service Unavailable</body></html>', ['Content-Type' => 'text/html']),
                'JSON uz Retry-After' => new Response(503, '{"success":false,"error":"Unavailable"}', ['Retry-After' => '5']),
            ] as $opis => $odgovor
        ) {
            ['api' => $api, 'stanje' => $stanje] = self::postavka([$odgovor, self::ok(), self::ok(), self::ok()], maxRetries: 3);

            $izuzetak = null;
            try {
                $api->javniPost('/fiskalizacija', ['napomena' => 'bez broja']);
            } catch (ServerException $e) {
                $izuzetak = $e;
            }

            self::assertInstanceOf(ServerException::class, $izuzetak, $opis);
            self::assertSame(503, $izuzetak->getCode(), $opis);
            self::assertCount(1, $stanje->zahtevi, "{$opis}: tačno jedan zahtev");
            self::assertSame([], $stanje->pauze, "{$opis}: bez pauze");
            $upozorenja = self::upozorenja($stanje);
            self::assertCount(1, $upozorenja, $opis);
            self::assertStringContainsString('nije poznat', $upozorenja[0]['message'], $opis);
            self::assertSame(503, $upozorenja[0]['context']['status'] ?? null, $opis);
        }
    }

    /**
     * Bezbedan zahtev (GET i POST `/fiskalizacija` sa brojem) ponavlja se
     * na svaki ishod iz 1.1.1: mrežna greška (sve tri vrednosti
     * `wasRequestSent()`), 429, 502, 503, 504.
     *
     * @return iterable<string, array{Response|NetworkException}>
     */
    public static function sviPonavljaniIshodi(): iterable
    {
        yield 'mrezna greska, nepoznato' => [new NetworkException('timeout', 28)];
        yield 'mrezna greska, poslato' => [new NetworkException('timeout', 28, requestSent: true)];
        yield 'mrezna greska, nije poslato' => [new NetworkException('odbijeno', 7, requestSent: false)];
        foreach ([429, 502, 503, 504] as $status) {
            yield (string) $status => [new Response($status, '{"success":false,"error":"x"}')];
        }
    }

    #[DataProvider('sviPonavljaniIshodi')]
    public function testBezbedanZahtevSePonavljaKaoU111(Response|NetworkException $ishod): void
    {
        foreach (
            [
                'GET' => static fn (AbstractApi $api) => $api->javniGet('/status'),
                'POST fiskalizacija sa brojem' => static fn (AbstractApi $api) => $api->javniPost('/fiskalizacija', ['broj_racuna' => 'SHOP-1']),
            ] as $opis => $poziv
        ) {
            ['api' => $api, 'stanje' => $stanje] = self::postavka([$ishod, $ishod, self::ok()]);

            $poziv($api);

            self::assertCount(3, $stanje->zahtevi, $opis);
            self::assertSame([1000, 2000], $stanje->pauze, $opis);
        }
    }

    /**
     * Bezbedan zahtev posle iscrpljenih pokušaja baca poslednji izuzetak kao
     * u 1.1.1 (1 + maxRetries zahteva), bez upozorenja o nepoznatom ishodu.
     */
    public function testBezbedanZahtevIscrpljujePokusajeKaoU111(): void
    {
        ['api' => $api, 'stanje' => $stanje] = self::postavka(array_fill(0, 3, new NetworkException('timeout', 28)));

        try {
            $api->javniPost('/fiskalizacija', ['broj_racuna' => 'SHOP-1']);
            $izuzetak = null;
        } catch (NetworkException $e) {
            $izuzetak = $e;
        }

        self::assertInstanceOf(NetworkException::class, $izuzetak);
        self::assertCount(3, $stanje->zahtevi);
        foreach (self::upozorenja($stanje) as $z) {
            self::assertStringNotContainsString('nije poznat', $z['message']);
        }
    }

    /**
     * Javne metode klijenta: `fiskalizuj()` bez broja, `send()` i `run()`
     * idu jednom posle 504; `fiskalizuj()` sa brojem i GET metode se
     * ponavljaju.
     */
    public function testJavneMetodeKlijenta(): void
    {
        $racun = static fn (): Invoice => Invoice::create()
            ->setPaymentType(PaymentType::Kartica)
            ->addItem(new InvoiceItem(naziv: 'Test', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20));
        $ok = new Response(200, (string) json_encode([
            'success' => true,
            'data' => ['rezultat' => ['pfr_broj' => 'P-1', 'racun_id' => 1], 'racuni' => [], 'ukupno' => 0],
        ]));

        foreach (
            [
                'fiskalizuj bez broja' => [static fn (EfiskalizacijaClient $c) => $c->fiskalizacija()->fiskalizuj($racun()), 1],
                'fiskalizuj sa praznim brojem' => [static fn (EfiskalizacijaClient $c) => $c->fiskalizacija()->fiskalizuj($racun()->setInvoiceNumber('   ')), 1],
                'send' => [static fn (EfiskalizacijaClient $c) => $c->email()->send('P-1', 'a@b.rs'), 1],
                'run' => [static fn (EfiskalizacijaClient $c) => $c->test()->run(), 1],
                'fiskalizuj sa brojem' => [static fn (EfiskalizacijaClient $c) => $c->fiskalizacija()->fiskalizuj($racun()->setInvoiceNumber('SHOP-1')), 2],
                'fetch' => [static fn (EfiskalizacijaClient $c) => $c->status()->fetch(), 2],
                'list' => [static fn (EfiskalizacijaClient $c) => $c->invoices()->list(), 2],
                'download' => [static fn (EfiskalizacijaClient $c) => $c->pdf()->download('P-1'), 2],
            ] as $opis => [$poziv, $ocekivanoZahteva]
        ) {
            $stanje = new \stdClass();
            $stanje->zahteva = 0;
            $http = new class ($stanje, $ok) implements HttpClientInterface {
                public function __construct(private \stdClass $stanje, private Response $ok)
                {
                }

                public function request(string $method, string $url, array $headers = [], string $body = ''): Response
                {
                    return ++$this->stanje->zahteva === 1 ? new Response(504, '{"success":false,"error":"x"}') : $this->ok;
                }
            };
            $config = new Config(self::KLJUC, self::TAJNA, maxRetries: 2, retryBaseDelayMs: 0);

            try {
                $poziv(new EfiskalizacijaClient($config, $http));
            } catch (ServerException) {
                // očekivano kad se ne ponavlja
            }

            self::assertSame($ocekivanoZahteva, $stanje->zahteva, $opis);
        }
    }
}
