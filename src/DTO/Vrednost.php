<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

/**
 * Tolerantno čitanje vrednosti iz JSON odgovora i webhook tela: vrednost u
 * očekivanom tipu prolazi, jednoznačan drugi oblik (broj kao tekst, tekst kao
 * broj) se prevodi, a sve ostalo daje `null` — nikad TypeError.
 *
 * @internal Nije deo javnog API-ja SDK-a.
 */
final class Vrednost
{
    private function __construct()
    {
    }

    /** Tekst; ceo broj i razlomak postaju tekst, ostalo (bool, niz, null) null. */
    public static function tekst(mixed $v): ?string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }

        return null;
    }

    /** Ceo broj; tekst samo od cifara (uz znak) i razlomak bez decimala se prevode. */
    public static function ceoBroj(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^\s*-?\d{1,18}\s*$/', $v) === 1) {
            return (int) $v;
        }
        if (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e15) {
            return (int) $v;
        }

        return null;
    }

    /** Iznos; ceo broj, razlomak i numerički tekst se prevode u float. */
    public static function iznos(mixed $v): ?float
    {
        if (is_int($v) || (is_float($v) && is_finite($v))) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            $broj = (float) $v;

            return is_finite($broj) ? $broj : null;
        }

        return null;
    }

    /** Logička vrednost; 1/0 i tekst `true`/`false`/`1`/`0` se prevode, ostalo null. */
    public static function logicka(mixed $v): ?bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === 1 || $v === 0) {
            return $v === 1;
        }
        if (is_string($v)) {
            return match (strtolower(trim($v))) {
                'true', '1' => true,
                'false', '0' => false,
                default => null,
            };
        }

        return null;
    }
}
