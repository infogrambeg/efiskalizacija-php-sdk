<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\Auth\HmacSigner;
use Efiskalizacija\Config;
use Efiskalizacija\EfiskalizacijaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * API tajna (i ključ) se ne vide u tragu izuzetka iz `Config`-a i fabrika
 * SDK-a: parametri nose `#[\SensitiveParameter]`.
 *
 * Proverava se u POD-PROCESU sa `zend.exception_ignore_args=0` i najvećim
 * `zend.exception_string_param_max_len`, jer ini PHPUnit procesa (ili produkcioni
 * php.ini sa `ignore_args=On`) može sam da sakrije argumente i učini test
 * slepim. Pozitivna kontrola u istom procesu (funkcija bez atributa) mora da
 * pokaže i ključ i tajnu.
 *
 * @trace R2
 */
final class TajnaUTraguTest extends TestCase
{
    /** @var array{ini_ignore_args: string, slucajevi: array<string, array<string, mixed>>}|null */
    private static ?array $rezultat = null;

    /** @return array{ini_ignore_args: string, slucajevi: array<string, array<string, mixed>>} */
    private static function rezultat(): array
    {
        if (self::$rezultat === null) {
            ['izlaz' => $izlaz, 'greske' => $greske, 'kod' => $kod] = PodProces::izvrsi([
                PHP_BINARY,
                '-d', 'zend.exception_ignore_args=0',
                '-d', 'zend.exception_string_param_max_len=1000000',
                '-d', 'display_errors=stderr',
                '-d', 'error_reporting=E_ALL',
                dirname(__DIR__) . '/Fixtures/tajna-u-tragu.php',
            ]);
            self::assertSame('', $greske, 'stderr pod-procesa');
            self::assertSame(0, $kod, 'izlaz pod-procesa: ' . $izlaz);
            self::$rezultat = json_decode($izlaz, true, 16, JSON_THROW_ON_ERROR);
        }

        return self::$rezultat;
    }

    public function testPozitivnaKontrolaVidiKljucITajnu(): void
    {
        $r = self::rezultat();

        self::assertSame('0', $r['ini_ignore_args']);
        $kontrola = $r['slucajevi']['kontrola'];
        self::assertTrue($kontrola['baceno']);
        self::assertTrue($kontrola['tajna_u_tragu'], 'kontrola: tajna mora biti u getTrace()');
        self::assertTrue($kontrola['kljuc_u_tragu'], 'kontrola: ključ mora biti u getTrace()');
        self::assertTrue($kontrola['tajna_u_tekstu'], 'kontrola: tajna mora biti u (string) $e');
        self::assertTrue($kontrola['kljuc_u_tekstu'], 'kontrola: ključ mora biti u (string) $e');
    }

    /**
     * Slučaj, i da li fixture tom pozivu prosleđuje NEPRAZAN ključ / tajnu
     * (prazna vrednost se ne može „videti" u tragu ni bez atributa).
     *
     * @return iterable<string, array{string, bool, bool}>
     */
    public static function slucajevi(): iterable
    {
        foreach ([
            ['Config maxRetries 11', true, true],
            ['Config maxRetries -1', true, true],
            ['Config retryBaseDelayMs -1', true, true],
            ['Config retryMultiplier 0', true, true],
            ['Config prazan kljuc', false, true],
            ['Config prazna tajna', true, false],
            ['Config::sandbox prazan kljuc', false, true],
            ['Config::production prazan kljuc', false, true],
            ['Config::sandbox prazna tajna', true, false],
            ['EfiskalizacijaClient::create prazan kljuc', false, true],
            ['EfiskalizacijaClient::sandbox prazan kljuc', false, true],
            ['EfiskalizacijaClient::create prazna tajna', true, false],
        ] as [$ime, $kljucProsledjen, $tajnaProsledjena]) {
            yield $ime => [$ime, $kljucProsledjen, $tajnaProsledjena];
        }
    }

    /**
     * Očekivano u tragu (uz `zend.exception_ignore_args=0`): na PHP ≥ 8.2
     * `#[\SensitiveParameter]` krije obe vrednosti; na PHP 8.1 atribut nema
     * efekta, pa se vidi svaka prosleđena neprazna vrednost — dokumentovano
     * ponašanje iz README („Na PHP 8.1 atribut nema efekta").
     *
     * @return array{kljuc: bool, tajna: bool}
     */
    public static function ocekivanoUTragu(int $phpVersionId, bool $kljucProsledjen, bool $tajnaProsledjena): array
    {
        if ($phpVersionId >= 80200) {
            return ['kljuc' => false, 'tajna' => false];
        }

        return ['kljuc' => $kljucProsledjen, 'tajna' => $tajnaProsledjena];
    }

    #[DataProvider('slucajevi')]
    public function testTragIzuzetkaNosiKljucITajnuSamoPoVerzijiPhpa(
        string $ime,
        bool $kljucProsledjen,
        bool $tajnaProsledjena,
    ): void {
        $slucaj = self::rezultat()['slucajevi'][$ime] ?? null;
        $ocekivano = self::ocekivanoUTragu(PHP_VERSION_ID, $kljucProsledjen, $tajnaProsledjena);

        self::assertIsArray($slucaj, "slučaj {$ime} nije pokrenut");
        self::assertTrue($slucaj['baceno'], "{$ime}: mora baciti izuzetak");
        self::assertSame(\InvalidArgumentException::class, $slucaj['klasa']);
        self::assertSame($ocekivano['tajna'], $slucaj['tajna_u_tragu'], "{$ime}: tajna u getTrace()");
        self::assertSame($ocekivano['kljuc'], $slucaj['kljuc_u_tragu'], "{$ime}: ključ u getTrace()");
        self::assertSame($ocekivano['tajna'], $slucaj['tajna_u_tekstu'], "{$ime}: tajna u (string) \$e");
        self::assertSame($ocekivano['kljuc'], $slucaj['kljuc_u_tekstu'], "{$ime}: ključ u (string) \$e");
    }

    /**
     * Grana za PHP 8.1 se ne izvršava uz instaliran PHPUnit 11 (traži PHP ≥ 8.2),
     * pa se ovde bar drži njen ugovor: na 8.1 je vidljiva svaka prosleđena
     * vrednost, na 8.2+ nijedna. Izlaz fixture-a na 8.1 se poredi sa ovim
     * ugovorom ručno (`docker run … php:8.1-cli … tests/Fixtures/tajna-u-tragu.php`).
     */
    public function testOcekivanjePoVerzijiPhpa(): void
    {
        self::assertSame(['kljuc' => true, 'tajna' => true], self::ocekivanoUTragu(80100, true, true));
        self::assertSame(['kljuc' => false, 'tajna' => true], self::ocekivanoUTragu(80199, false, true));
        self::assertSame(['kljuc' => true, 'tajna' => false], self::ocekivanoUTragu(80133, true, false));
        self::assertSame(['kljuc' => false, 'tajna' => false], self::ocekivanoUTragu(80200, true, true));
        self::assertSame(['kljuc' => false, 'tajna' => false], self::ocekivanoUTragu(80500, true, true));
    }

    /**
     * Javni ulazi koji primaju ključ ili tajnu nose atribut (i `HmacSigner`,
     * koji danas ne baca, da buduća provera ne otvori isto curenje).
     *
     * @return iterable<string, array{class-string, string, string}>
     */
    public static function osetljiviParametri(): iterable
    {
        foreach ([
            [Config::class, '__construct'],
            [Config::class, 'sandbox'],
            [Config::class, 'production'],
            [EfiskalizacijaClient::class, 'create'],
            [EfiskalizacijaClient::class, 'sandbox'],
        ] as [$klasa, $metoda]) {
            foreach (['apiKey', 'apiSecret'] as $parametar) {
                yield "{$klasa}::{$metoda} \${$parametar}" => [$klasa, $metoda, $parametar];
            }
        }
        yield HmacSigner::class . '::__construct $apiSecret' => [HmacSigner::class, '__construct', 'apiSecret'];
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
