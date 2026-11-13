<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kompatibilnost unazad za postojeće podklase `AbstractApi`: tačke zamene
 * sata i pauze (1.1.0) ne ulaze u imenski prostor metoda podklase. Podklasa
 * koja već ima sopstveni `pause()` ili `currentTime()` (bilo kog potpisa i
 * vidljivosti), ili metodu sa imenom privatnog pomoćnika 1.1.0 (`sleepFor`,
 * `clockNow`, `nextTimestamp`, `queryString`), se učitava bez Fatal-a, a SDK
 * njene metode ne poziva.
 *
 * Podklasa se deklariše u pod-procesu
 * (`tests/Fixtures/podklasa-tacke-prosirenja.php`): Fatal pri nasleđivanju
 * ubio bi proces testova.
 *
 * @trace R1 R2
 */
final class PodklasaAbstractApiTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function oblici(): iterable
    {
        yield 'private pause()' => ['private pause()'];
        yield 'currentTime(): float' => ['currentTime(): float'];
        yield 'pause(int $sekundi)' => ['pause(int $sekundi)'];
        // Imena privatnih pomoćnika 1.1.0, drugi potpis, svaka vidljivost:
        // pomoćnik koji bi postao protected/public obara ove oblike (Fatal
        // pri nasleđivanju ili SDK zove metodu podklase).
        foreach (['sleepFor', 'clockNow', 'nextTimestamp', 'queryString'] as $ime) {
            foreach (['private', 'protected', 'public', 'static'] as $vidljivost) {
                yield $ime . ' ' . $vidljivost => [$ime . ' ' . $vidljivost];
            }
        }
    }

    #[DataProvider('oblici')]
    public function testPodklasaSaSopstvenomMetodomRadiISdkJeNePoziva(string $oblik): void
    {
        $psrLog = dirname((string) (new \ReflectionClass(\Psr\Log\LoggerInterface::class))->getFileName());
        // stderr kroz fajl, ne cev: velik stderr ne zaglavljuje test (PodProcesTest)
        ['izlaz' => $izlaz, 'greske' => $greske, 'kod' => $kod] = PodProces::izvrsi(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', dirname(__DIR__) . '/Fixtures/podklasa-tacke-prosirenja.php', $oblik, $psrLog],
        );

        self::assertSame('', $greske, 'stderr pod-procesa');
        self::assertSame(0, $kod, 'izlaz pod-procesa: ' . $izlaz);
        $r = json_decode($izlaz, true, 8, JSON_THROW_ON_ERROR);

        self::assertSame([], $r['pozvano'], 'SDK ne sme da zove metode podklase');
        // Pozitivna kontrola: ponovni pokušaj se desio i dobio strogo veći timestamp
        self::assertCount(2, $r['vremena']);
        self::assertGreaterThan((int) $r['vremena'][0], (int) $r['vremena'][1]);
    }
}
