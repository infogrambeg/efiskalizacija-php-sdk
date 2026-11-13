<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

/**
 * Pokretanje pod-procesa u testovima. Nije test klasa.
 *
 * stderr deteta ide u privremeni fajl, ne u cev: čitanje stdout cevi do EOF
 * pa tek onda stderr cevi zaglavljuje oba procesa čim dete na stderr upiše
 * više nego što staje u cev (~64 KB) — dete čeka da se stderr isprazni, a
 * roditelj čeka EOF na stdout-u (čuva {@see PodProcesTest}).
 */
final class PodProces
{
    /**
     * @param list<string> $komanda
     *
     * @return array{izlaz: string, greske: string, kod: int}
     */
    public static function izvrsi(array $komanda): array
    {
        $greskeFajl = tempnam(sys_get_temp_dir(), 'efisk-sdk-stderr-');
        if ($greskeFajl === false) {
            throw new \RuntimeException('privremeni fajl za stderr nije napravljen');
        }

        try {
            $proces = proc_open(
                $komanda,
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $greskeFajl, 'w']],
                $cevi,
            );
            if (!is_resource($proces)) {
                throw new \RuntimeException('pod-proces nije pokrenut');
            }
            $izlaz = (string) stream_get_contents($cevi[1]);
            fclose($cevi[1]);
            $kod = proc_close($proces);

            return ['izlaz' => $izlaz, 'greske' => (string) file_get_contents($greskeFajl), 'kod' => $kod];
        } finally {
            @unlink($greskeFajl);
        }
    }
}
