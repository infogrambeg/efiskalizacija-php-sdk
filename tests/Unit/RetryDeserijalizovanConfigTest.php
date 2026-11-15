<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Api\AbstractApi;
use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\Exception\NetworkException;
use Efiskalizacija\Exception\ServerException;
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `Config` koji nije prošao konstruktor (`unserialize()` objekta iz 1.1.0 ili
 * ručno sastavljenog niza) može nositi vrednosti koje konstruktor 1.1.1
 * odbija. `AbstractApi` ih i tada drži u granicama: pauza je int u
 * [0, `Config::MAX_RETRY_DELAY_MS`] bez TypeError/DivisionByZeroError, a broj
 * ponovljenih pokušaja najviše `Config::MAX_RETRIES`; negativan `maxRetries`
 * baca `InvalidArgumentException` pre prvog zahteva.
 */
final class RetryDeserijalizovanConfigTest extends TestCase
{
    /**
     * Config sa zadatim vrednostima svojstava, napravljen kroz `unserialize()`
     * (konstruktor se ne poziva).
     *
     * @param array<string, int> $svojstva
     */
    private static function deserijalizovan(array $svojstva): Config
    {
        $serijal = serialize(new Config('k', 's'));
        foreach ($svojstva as $ime => $vrednost) {
            $sidro = "Efiskalizacija\\Config\0{$ime}\";i:";
            self::assertSame(1, substr_count($serijal, $sidro), "svojstvo {$ime} u serijalu tačno jednom");
            $pocetak = (int) strpos($serijal, $sidro) + strlen($sidro);
            $kraj = strpos($serijal, ';', $pocetak);
            self::assertNotFalse($kraj);
            $serijal = substr($serijal, 0, $pocetak) . $vrednost . substr($serijal, $kraj);
        }

        $config = unserialize($serijal, ['allowed_classes' => [Config::class]]);
        self::assertInstanceOf(Config::class, $config);
        // Kontrola: vrednosti su zaista stigle mimo konstruktora.
        foreach ($svojstva as $ime => $vrednost) {
            self::assertSame($vrednost, (new \ReflectionProperty(Config::class, $ime))->getValue($config), $ime);
        }

        return $config;
    }

    /**
     * Pokretanje poziva dok server uvek vraća 503 (ili mreža uvek pada).
     * Hvataju se SAMO očekivani izuzeci poslednjeg pokušaja; TypeError,
     * DivisionByZeroError i „Iscrpljeni svi pokusaji" ruše test.
     *
     * @return array{pauze: list<int>, zahteva: int, izuzetak: class-string, poruka: ?string}
     */
    private static function pokreni(Config $config, bool $mreznaGreska = false): array
    {
        $stanje = new \stdClass();
        $stanje->pauze = [];
        $stanje->zahteva = 0;

        $http = new class ($stanje, $mreznaGreska) implements HttpClientInterface {
            public function __construct(private \stdClass $stanje, private bool $mreznaGreska)
            {
            }

            public function request(string $method, string $url, array $headers = [], string $body = ''): Response
            {
                $this->stanje->zahteva++;
                // Osigurač: neograničen broj pokušaja ruši test greškom, ne
                // iscrpljenjem memorije celog PHPUnit procesa.
                if ($this->stanje->zahteva > 100) {
                    throw new \LogicException('vise od 100 zahteva u jednom pozivu');
                }
                if ($this->mreznaGreska) {
                    throw new NetworkException('cURL greska (7): odbijeno', 7);
                }

                return new Response(503, '{"success":false,"error":"Unavailable"}');
            }
        };

        $api = new class (
            $config,
            $http,
            new HmacSigner('s'),
            null,
            static fn (): int => 1_700_000_000,
            static function (int $mikrosekunde) use ($stanje): void {
                $stanje->pauze[] = $mikrosekunde;
            },
        ) extends AbstractApi {
            /** @return array<string, mixed> */
            public function status(): array
            {
                return $this->get('/status');
            }
        };

        $izuzetak = null;
        $poruka = null;
        try {
            $api->status();
        } catch (ServerException | NetworkException | \InvalidArgumentException $e) {
            $izuzetak = $e::class;
            $poruka = $e->getMessage();
        }
        self::assertNotNull($izuzetak, 'poziv je morao da iscrpi pokušaje ili da odbije Config');

        return ['pauze' => $stanje->pauze, 'zahteva' => $stanje->zahteva, 'izuzetak' => $izuzetak, 'poruka' => $poruka];
    }

    /**
     * @return iterable<string, array{array<string, int>, list<int>}>
     */
    public static function pauze(): iterable
    {
        $s = 1_000_000;

        // A1 (bez max(…, 0)): -5000 µs pa 60 s; PHP_INT_MIN → TypeError
        yield 'baza -5' => [['retryBaseDelayMs' => -5], [0, 0, 0]];
        yield 'baza PHP_INT_MIN' => [['retryBaseDelayMs' => PHP_INT_MIN], [0, 0, 0]];
        yield 'baza -5, množilac 0' => [['retryBaseDelayMs' => -5, 'retryMultiplier' => 0], [0, 0, 0]];
        // A4 (`=== 1`) i A14 (bez uslova množioca): 0 daje 1 s, 0, 0;
        // -2 daje negativnu pauzu; PHP_INT_MIN prelivanje → TypeError
        yield 'množilac 0' => [['retryMultiplier' => 0], [$s, $s, $s]];
        yield 'množilac -2' => [['retryMultiplier' => -2], [$s, $s, $s]];
        yield 'množilac PHP_INT_MIN' => [['retryMultiplier' => PHP_INT_MIN], [$s, $s, $s]];
        yield 'množilac PHP_INT_MIN, baza 60000' => [
            ['retryMultiplier' => PHP_INT_MIN, 'retryBaseDelayMs' => 60000],
            [60 * $s, 60 * $s, 60 * $s],
        ];
        yield 'baza PHP_INT_MIN, množilac PHP_INT_MIN' => [
            ['retryBaseDelayMs' => PHP_INT_MIN, 'retryMultiplier' => PHP_INT_MIN],
            [0, 0, 0],
        ];
    }

    /**
     * @param array<string, int> $svojstva
     * @param list<int> $ocekivano
     *
     * @trace R4
     */
    #[DataProvider('pauze')]
    public function testPauzaIzDeserijalizovanogConfigaJeUGranicama(array $svojstva, array $ocekivano): void
    {
        $config = self::deserijalizovan($svojstva);

        self::assertSame($ocekivano, self::pokreni($config)['pauze']);
        self::assertSame($ocekivano, self::pokreni($config, true)['pauze'], 'ista pauza i za mrežnu grešku');
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function brojPokusaja(): iterable
    {
        yield 'maxRetries 40 → 1 + 10' => [40, 11];
        yield 'maxRetries PHP_INT_MAX → 1 + 10' => [PHP_INT_MAX, 11];
        yield 'maxRetries 10 → 1 + 10' => [10, 11];
        yield 'maxRetries 0 → 1' => [0, 1];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function negativanMaxRetries(): iterable
    {
        yield 'maxRetries -1' => [-1];
        yield 'maxRetries PHP_INT_MIN' => [PHP_INT_MIN];
    }

    /**
     * Negativan `maxRetries` je fail-closed: `InvalidArgumentException` PRE
     * prvog zahteva (0 poziva HTTP klijenta), i za server koji vraća 503 i za
     * mrežnu grešku.
     *
     * @trace R4
     */
    #[DataProvider('negativanMaxRetries')]
    public function testNegativanMaxRetriesIzDeserijalizovanogConfigaBacaPrePrvogZahteva(int $maxRetries): void
    {
        $config = self::deserijalizovan(['maxRetries' => $maxRetries]);

        foreach (['server 503' => false, 'mrežna greška' => true] as $opis => $mreznaGreska) {
            $ishod = self::pokreni($config, $mreznaGreska);
            self::assertSame(0, $ishod['zahteva'], "broj zahteva ({$opis})");
            self::assertSame([], $ishod['pauze'], "pauze ({$opis})");
            self::assertSame(\InvalidArgumentException::class, $ishod['izuzetak'], $opis);
            self::assertSame(
                'maxRetries mora biti izmedju 0 i ' . Config::MAX_RETRIES . ", zadato {$maxRetries}",
                $ishod['poruka'],
                $opis,
            );
        }
    }

    /** @trace R4 */
    #[DataProvider('brojPokusaja')]
    public function testBrojPokusajaIzDeserijalizovanogConfigaJeOgranicen(int $maxRetries, int $zahteva): void
    {
        $config = self::deserijalizovan(['maxRetries' => $maxRetries]);

        $server = self::pokreni($config);
        self::assertSame($zahteva, $server['zahteva'], 'broj zahteva (503)');
        self::assertSame(ServerException::class, $server['izuzetak']);
        self::assertCount($zahteva - 1, $server['pauze']);

        $mreza = self::pokreni($config, true);
        self::assertSame($zahteva, $mreza['zahteva'], 'broj zahteva (mrežna greška)');
        self::assertSame(NetworkException::class, $mreza['izuzetak']);
    }

    /** Kontrola: Config iz konstruktora i dalje daje maxRetries + 1 zahteva. */
    public function testKonstruisanConfigDajeMaxRetriesPlusJedan(): void
    {
        self::assertSame(11, self::pokreni(new Config('k', 's', maxRetries: 10, retryBaseDelayMs: 0))['zahteva']);
        self::assertSame(1, self::pokreni(new Config('k', 's', maxRetries: 0))['zahteva']);
    }
}
