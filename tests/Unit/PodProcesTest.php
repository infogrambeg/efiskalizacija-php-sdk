<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * {@see PodProces::izvrsi()} ne zaglavljuje roditelja kad dete piše više na
 * stderr nego što staje u cev (~64 KB): čitanje stdout-a do EOF pa tek onda
 * stderr-a čeka dete koje čeka da se stderr isprazni.
 *
 * `izvrsi()` se poziva iz posrednog procesa čiji izlaz ide u fajlove, pa test
 * sam ne može da se zaglavi: posle roka se posrednik ubija i test pada.
 */
final class PodProcesTest extends TestCase
{
    private const ROK_SEKUNDI = 10;

    public function testDeteSa200KbNaStderrNeZaglavljujeRoditelja(): void
    {
        $izlazFajl = (string) tempnam(sys_get_temp_dir(), 'efisk-sdk-pp-out-');
        $greskeFajl = (string) tempnam(sys_get_temp_dir(), 'efisk-sdk-pp-err-');
        $dete = 'fwrite(STDERR, str_repeat("E", 200000)); fwrite(STDOUT, "ok");';
        $posrednik = 'require ' . var_export(__DIR__ . '/PodProces.php', true) . ';'
            . '$r = \\Efiskalizacija\\Tests\\Unit\\PodProces::izvrsi([PHP_BINARY, "-r", ' . var_export($dete, true) . ']);'
            . 'echo json_encode([$r["izlaz"], strlen($r["greske"]), $r["kod"]]);';

        try {
            $proces = proc_open(
                [PHP_BINARY, '-r', $posrednik],
                [0 => ['file', '/dev/null', 'r'], 1 => ['file', $izlazFajl, 'w'], 2 => ['file', $greskeFajl, 'w']],
                $cevi,
            );
            self::assertIsResource($proces);
            $kraj = microtime(true) + self::ROK_SEKUNDI;
            while (proc_get_status($proces)['running'] && microtime(true) < $kraj) {
                usleep(50000);
            }
            $zaglavljen = proc_get_status($proces)['running'];
            if ($zaglavljen) {
                proc_terminate($proces, 9);
            }
            proc_close($proces);

            self::assertFalse($zaglavljen, 'roditelj zaglavljen posle ' . self::ROK_SEKUNDI . ' s');
            self::assertSame('', (string) file_get_contents($greskeFajl));
            self::assertSame(['ok', 200000, 0], json_decode((string) file_get_contents($izlazFajl), true, 4, JSON_THROW_ON_ERROR));
        } finally {
            @unlink($izlazFajl);
            @unlink($greskeFajl);
        }
    }
}
