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
 * Granice konfiguracije ponavljanja (`Config`) i pauza između pokušaja
 * (`AbstractApi`) od 1.1.1.
 */
final class RetryGraniceTest extends TestCase
{
    /** @trace R2 R4 */
    public function testKonstanteGranica(): void
    {
        self::assertSame(10, Config::MAX_RETRIES);
        self::assertSame(60000, Config::MAX_RETRY_DELAY_MS);
    }

    // ========== R2: maxRetries ==========

    /** @return iterable<string, array{int}> */
    public static function dozvoljeniMaxRetries(): iterable
    {
        yield '0' => [0];
        yield '3 (podrazumevano)' => [3];
        yield '10' => [10];
    }

    /** @trace R2 */
    #[DataProvider('dozvoljeniMaxRetries')]
    public function testMaxRetriesUOpsegu(int $vrednost): void
    {
        $config = new Config('k', 's', maxRetries: $vrednost);

        self::assertSame($vrednost, $config->getMaxRetries());
    }

    /** @return iterable<string, array{int}> */
    public static function nedozvoljeniMaxRetries(): iterable
    {
        yield '-1' => [-1];
        yield '11' => [11];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN];
        yield 'PHP_INT_MAX' => [PHP_INT_MAX];
    }

    /** @trace R2 */
    #[DataProvider('nedozvoljeniMaxRetries')]
    public function testMaxRetriesVanOpsegaBacaIzuzetak(int $vrednost): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maxRetries mora biti izmedju 0 i 10');

        new Config('k', 's', maxRetries: $vrednost);
    }

    // ========== R3: retryBaseDelayMs i retryMultiplier ==========

    /** @trace R3 */
    public function testRetryBaseDelayMsNulaIVelikaVrednostProlaze(): void
    {
        self::assertSame(0, (new Config('k', 's', retryBaseDelayMs: 0))->getRetryBaseDelayMs());
        self::assertSame(PHP_INT_MAX, (new Config('k', 's', retryBaseDelayMs: PHP_INT_MAX))->getRetryBaseDelayMs());
    }

    /** @return iterable<string, array{int}> */
    public static function negativniBaseDelay(): iterable
    {
        yield '-1' => [-1];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN];
    }

    /** @trace R3 */
    #[DataProvider('negativniBaseDelay')]
    public function testNegativanRetryBaseDelayMsBacaIzuzetak(int $vrednost): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryBaseDelayMs ne sme biti negativan');

        new Config('k', 's', retryBaseDelayMs: $vrednost);
    }

    /** @trace R3 */
    public function testRetryMultiplierJedanIVelikaVrednostProlaze(): void
    {
        self::assertSame(1, (new Config('k', 's', retryMultiplier: 1))->getRetryMultiplier());
        self::assertSame(PHP_INT_MAX, (new Config('k', 's', retryMultiplier: PHP_INT_MAX))->getRetryMultiplier());
    }

    /** @return iterable<string, array{int}> */
    public static function multiplierIspodJedan(): iterable
    {
        yield '0' => [0];
        yield '-1' => [-1];
        yield 'PHP_INT_MIN' => [PHP_INT_MIN];
    }

    /** @trace R3 */
    #[DataProvider('multiplierIspodJedan')]
    public function testRetryMultiplierIspodJedanBacaIzuzetak(int $vrednost): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('retryMultiplier mora biti najmanje 1');

        new Config('k', 's', retryMultiplier: $vrednost);
    }

    // ========== R4: pauza između pokušaja ==========

    /**
     * Pauze (µs) koje AbstractApi traži kroz `$sleep` dok server uvek vraća
     * 503 (ili mreža uvek pada), pa poziv iscrpi sve pokušaje.
     *
     * @return list<int>
     */
    private static function pauze(Config $config, bool $mreznaGreska = false): array
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
                if ($this->mreznaGreska) {
                    throw new NetworkException('cURL greska (7): odbijeno', 7);
                }

                return new Response(503, '{"success":false,"error":"Unavailable"}');
            }
        };

        $api = new class (
            $config,
            $http,
            new HmacSigner($config->getApiSecret()),
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

        try {
            $api->status();
            self::fail('poziv je morao da iscrpi pokušaje');
        } catch (ServerException | NetworkException) {
            // očekivano posle poslednjeg pokušaja
        }
        self::assertSame($config->getMaxRetries() + 1, $stanje->zahteva, 'broj pokušaja');

        return $stanje->pauze;
    }

    /** @trace R4 */
    public function testPodrazumevanePauzeKaoU110(): void
    {
        self::assertSame([1_000_000, 2_000_000, 4_000_000], self::pauze(new Config('k', 's')));
        self::assertSame([1_000_000, 2_000_000, 4_000_000], self::pauze(new Config('k', 's'), true));
    }

    /**
     * @return iterable<string, array{int, int, int, list<int>}>
     */
    public static function konfiguracijePauze(): iterable
    {
        $max = 60_000_000;

        yield '1000 × 2, 10 pokušaja: granica od 7. pauze' => [10, 1000, 2, [
            1_000_000, 2_000_000, 4_000_000, 8_000_000, 16_000_000, 32_000_000, $max, $max, $max, $max,
        ]];
        yield 'baza i množilac PHP_INT_MAX, 10 pokušaja' => [10, PHP_INT_MAX, PHP_INT_MAX, array_fill(0, 10, $max)];
        yield 'baza PHP_INT_MAX, množilac 1' => [10, PHP_INT_MAX, 1, array_fill(0, 10, $max)];
        yield 'baza 1, množilac PHP_INT_MAX' => [10, 1, PHP_INT_MAX, array_merge([1000], array_fill(0, 9, $max))];
        yield 'baza 0, množilac PHP_INT_MAX' => [10, 0, PHP_INT_MAX, array_fill(0, 10, 0)];
        yield 'baza 60000 tačno na granici' => [3, 60000, 2, [$max, $max, $max]];
        yield 'baza 59999, množilac 1' => [3, 59999, 1, [59_999_000, 59_999_000, 59_999_000]];
        yield 'baza 30000 × 2 tačno granica' => [3, 30000, 2, [30_000_000, $max, $max]];
        yield 'baza 7 × 3, 10 pokušaja' => [10, 7, 3, [
            7_000, 21_000, 63_000, 189_000, 567_000, 1_701_000, 5_103_000, 15_309_000, 45_927_000, $max,
        ]];
        yield 'baza 2 × PHP_INT_MAX/2' => [3, 2, intdiv(PHP_INT_MAX, 2), [2_000, $max, $max]];
        yield 'maxRetries 0: bez pauze' => [0, PHP_INT_MAX, PHP_INT_MAX, []];
    }

    /**
     * @param list<int> $ocekivano
     *
     * @trace R4
     */
    #[DataProvider('konfiguracijePauze')]
    public function testPauzaJeOgranicenaIBezPrelivanja(int $maxRetries, int $baza, int $mnozilac, array $ocekivano): void
    {
        $config = new Config('k', 's', maxRetries: $maxRetries, retryBaseDelayMs: $baza, retryMultiplier: $mnozilac);

        self::assertSame($ocekivano, self::pauze($config));
        self::assertSame($ocekivano, self::pauze($config, true), 'ista pauza i za mrežnu grešku');
    }

    /**
     * Sve kombinacije iz mreže graničnih vrednosti: pauza je int u
     * [0, 60 s] i jednaka referenci izračunatoj tačnom aritmetikom (GMP bez
     * proširenja nije dostupan, pa referenca radi nad decimalnim nizovima).
     *
     * @trace R4
     */
    public function testMrezaVrednostiOdgovaraReferenci(): void
    {
        $vrednosti = [0, 1, 2, 3, 7, 999, 1000, 29999, 30000, 30001, 59999, 60000, 60001, 1 << 31, intdiv(PHP_INT_MAX, 60000), intdiv(PHP_INT_MAX, 2), PHP_INT_MAX - 1, PHP_INT_MAX];
        $provereno = 0;
        foreach ($vrednosti as $baza) {
            foreach ($vrednosti as $mnozilac) {
                if ($mnozilac < 1) {
                    continue;
                }
                $config = new Config('k', 's', maxRetries: Config::MAX_RETRIES, retryBaseDelayMs: $baza, retryMultiplier: $mnozilac);
                $ocekivano = [];
                for ($n = 1; $n <= Config::MAX_RETRIES; $n++) {
                    $ocekivano[] = self::referentnaPauzaMs($baza, $mnozilac, $n) * 1000;
                }
                self::assertSame($ocekivano, self::pauze($config), "baza={$baza} mnozilac={$mnozilac}");
                $provereno++;
            }
        }
        self::assertSame(18 * 17, $provereno);
    }

    /** min(baza × množilac^(n−1), 60000) kroz float-slobodnu aritmetiku nad nizovima. */
    private static function referentnaPauzaMs(int $baza, int $mnozilac, int $n): int
    {
        $granica = '60000';
        $proizvod = (string) $baza;
        for ($i = 1; $i < $n; $i++) {
            $proizvod = self::pomnozi($proizvod, (string) $mnozilac);
            if (self::veciIliJednak($proizvod, $granica)) {
                return 60000;
            }
        }

        return self::veciIliJednak($proizvod, $granica) ? 60000 : (int) $proizvod;
    }

    private static function pomnozi(string $a, string $b): string
    {
        $rez = array_fill(0, strlen($a) + strlen($b), 0);
        for ($i = strlen($a) - 1; $i >= 0; $i--) {
            for ($j = strlen($b) - 1; $j >= 0; $j--) {
                $p = (int) $a[$i] * (int) $b[$j] + $rez[$i + $j + 1];
                $rez[$i + $j + 1] = $p % 10;
                $rez[$i + $j] += intdiv($p, 10);
            }
        }
        $s = ltrim(implode('', $rez), '0');

        return $s === '' ? '0' : $s;
    }

    private static function veciIliJednak(string $a, string $b): bool
    {
        return strlen($a) !== strlen($b) ? strlen($a) > strlen($b) : strcmp($a, $b) >= 0;
    }
}
