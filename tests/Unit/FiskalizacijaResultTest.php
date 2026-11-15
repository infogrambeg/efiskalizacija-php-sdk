<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\FiskalizacijaResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `FiskalizacijaResult::fromArray()` čita `data.rezultat` onakav kakav server
 * šalje: `qr_kod` (ne `qr_code`) i `message` (ne `poruka`), uz polja ishoda
 * (`success`, `status`, `duplikat`, `error_code` ...). Do 1.2.0 SDK je čitao
 * `qr_code` i `poruka`, pa su `qrCode` i `poruka` uvek bili null.
 *
 * Oblici ispod su prepisani iz servera (`VirtualPFR::buildSuccessResult`,
 * `handleError`, `respondWithDuplicateReplay` u `api/multitenant.php`);
 * unakrsni dokaz nad pravim serverskim kodom je u glavnom repou
 * (`tests/Unit/SdkOdgovorMapiranjeTest.php`).
 *
 * @trace R1
 */
final class FiskalizacijaResultTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function uspeh(): array
    {
        return [
            'success' => true,
            'racun_id' => 123,
            'broj_racuna' => '2026-000077',
            'ukupan_iznos' => 2400.5,
            'pfr_broj' => 'AB12CD34-Ef5Gh6i7-101',
            'pfr_datum_vreme' => '2026-01-05T12:00:00',
            'qr_kod' => 'iVBORw0KGgo',
            'qr_url' => 'https://suf.purs.gov.rs/v/?vl=A1',
            'esir_oznaka' => '1522/1.2.0',
            'status' => 'success',
            'message' => 'Fiskalizacija uspešno izvršena',
            'duration_ms' => 842,
            'cert_warning' => null,
            'klijentski_broj' => 'SHOP-2026-001',
            'duplikat' => false,
        ];
    }

    public function testUspehCitaStvarneKljuceveServera(): void
    {
        $r = FiskalizacijaResult::fromArray(self::uspeh());

        self::assertTrue($r->isSuccess());
        self::assertTrue($r->success);
        self::assertSame('success', $r->status);
        self::assertSame('AB12CD34-Ef5Gh6i7-101', $r->pfrBroj);
        self::assertSame('iVBORw0KGgo', $r->qrCode);
        self::assertSame('https://suf.purs.gov.rs/v/?vl=A1', $r->qrUrl);
        self::assertSame(123, $r->racunId);
        self::assertSame('2026-000077', $r->brojRacuna);
        self::assertSame('SHOP-2026-001', $r->klijentskiBroj);
        self::assertFalse($r->duplikat);
        self::assertSame(2400.5, $r->ukupanIznos);
        self::assertSame('2026-01-05T12:00:00', $r->pfrDatumVreme);
        self::assertSame('1522/1.2.0', $r->esirOznaka);
        self::assertSame('Fiskalizacija uspešno izvršena', $r->poruka);
        self::assertNull($r->errorCode);
        self::assertNull($r->existingCopyInvoiceId);
        self::assertNull($r->requestId);
    }

    public function testReplayNosiDuplikatTrue(): void
    {
        $replay = self::uspeh();
        unset($replay['duration_ms'], $replay['cert_warning']);
        $replay['duplikat'] = true;
        $replay['ukupan_iznos'] = 1190;

        $r = FiskalizacijaResult::fromArray($replay);

        self::assertTrue($r->isSuccess());
        self::assertTrue($r->duplikat);
        self::assertSame(1190.0, $r->ukupanIznos);
    }

    /**
     * Neuspeh ostaje rezultat, ne izuzetak: `isSuccess()` je false, poruka
     * greške je u `poruka`, a `pfrBroj` prazan string (kao do sada).
     */
    public function testNeuspehJeRezultatSaPorukom(): void
    {
        $r = FiskalizacijaResult::fromArray([
            'success' => false,
            'racun_id' => null,
            'broj_racuna' => '2026-000078',
            'pfr_broj' => null,
            'qr_kod' => null,
            'qr_url' => null,
            'esir_oznaka' => null,
            'status' => 'error',
            'message' => 'VSDC timeout',
            'duration_ms' => 5,
        ]);

        self::assertFalse($r->isSuccess());
        self::assertFalse($r->success);
        self::assertSame('error', $r->status);
        self::assertSame('', $r->pfrBroj);
        self::assertNull($r->qrCode);
        self::assertNull($r->qrUrl);
        self::assertNull($r->racunId);
        self::assertSame('VSDC timeout', $r->poruka);
        self::assertNull($r->errorCode);
        self::assertNull($r->klijentskiBroj);
        self::assertNull($r->duplikat);
    }

    public function testOdbijenaKopijaNosiKodIPostojecuKopiju(): void
    {
        $r = FiskalizacijaResult::fromArray([
            'success' => false,
            'status' => 'error',
            'message' => 'Kopija je već izdata',
            'error_code' => 'copy_already_issued',
            'existing_copy_invoice_id' => 158,
        ]);

        self::assertFalse($r->isSuccess());
        self::assertSame('copy_already_issued', $r->errorCode);
        self::assertSame(158, $r->existingCopyInvoiceId);
    }

    /** Stari ključevi `qr_code` i `poruka` i dalje rade kad novih nema. */
    public function testRezervniKljuceviQrCodeIPoruka(): void
    {
        $r = FiskalizacijaResult::fromArray(['pfr_broj' => 'X-1', 'qr_code' => 'STARI-QR', 'poruka' => 'stara poruka']);

        self::assertSame('STARI-QR', $r->qrCode);
        self::assertSame('stara poruka', $r->poruka);
    }

    public function testNoviKljuceviImajuPrednostNadStarim(): void
    {
        $r = FiskalizacijaResult::fromArray(['qr_kod' => 'NOVI', 'qr_code' => 'STARI', 'message' => 'nova', 'poruka' => 'stara']);

        self::assertSame('NOVI', $r->qrCode);
        self::assertSame('nova', $r->poruka);
    }

    /**
     * Odgovor bez `success` (stariji server ili sopstveni dvojnik): ishod se
     * izvodi iz nepraznog `pfr_broj`.
     */
    #[DataProvider('bezSuccess')]
    public function testIsSuccessBezPoljaSuccess(array $rezultat, bool $ocekivano): void
    {
        $r = FiskalizacijaResult::fromArray($rezultat);

        self::assertNull($r->success);
        self::assertSame($ocekivano, $r->isSuccess());
    }

    /** @return array<string, array{array<string, mixed>, bool}> */
    public static function bezSuccess(): array
    {
        return [
            'pfr broj postoji' => [['pfr_broj' => 'AB-1'], true],
            'pfr broj prazan' => [['pfr_broj' => ''], false],
            'pfr broj od razmaka' => [['pfr_broj' => '   '], false],
            'pfr broj null' => [['pfr_broj' => null], false],
            'prazan rezultat' => [[], false],
        ];
    }

    /** `success: false` uz neprazan `pfr_broj` je i dalje neuspeh: server odlučuje. */
    public function testSuccessFalseImaPrednostNadPfrBrojem(): void
    {
        self::assertFalse(FiskalizacijaResult::fromArray(['success' => false, 'pfr_broj' => 'AB-1'])->isSuccess());
    }

    /**
     * Tolerantni tipovi: broj kao tekst i tekst kao broj se prevode, a
     * neispravna vrednost daje null umesto TypeError-a.
     */
    public function testTolerantniTipovi(): void
    {
        $r = FiskalizacijaResult::fromArray([
            'success' => 'true',
            'racun_id' => '123',
            'broj_racuna' => 77,
            'ukupan_iznos' => '1190.00',
            'pfr_broj' => 101,
            'duplikat' => 1,
            'existing_copy_invoice_id' => '158',
            'qr_kod' => ['nije', 'tekst'],
            'message' => 12,
        ]);

        self::assertTrue($r->success);
        self::assertSame(123, $r->racunId);
        self::assertSame('77', $r->brojRacuna);
        self::assertSame(1190.0, $r->ukupanIznos);
        self::assertSame('101', $r->pfrBroj);
        self::assertTrue($r->duplikat);
        self::assertSame(158, $r->existingCopyInvoiceId);
        self::assertNull($r->qrCode);
        self::assertSame('12', $r->poruka);
    }

    #[DataProvider('neispravneVrednosti')]
    public function testNeispravneVrednostiDajuNull(string $kljuc, mixed $vrednost, string $svojstvo): void
    {
        $r = FiskalizacijaResult::fromArray([$kljuc => $vrednost]);

        self::assertNull($r->{$svojstvo}, "{$kljuc}: " . var_export($vrednost, true));
    }

    /** @return array<string, array{string, mixed, string}> */
    public static function neispravneVrednosti(): array
    {
        return [
            'racun_id tekst' => ['racun_id', 'abc', 'racunId'],
            'racun_id razlomak' => ['racun_id', 12.5, 'racunId'],
            'racun_id niz' => ['racun_id', [1], 'racunId'],
            'racun_id bool' => ['racun_id', true, 'racunId'],
            // Cifre unutar teksta nisu broj (regex mora biti usidren).
            'racun_id abc12' => ['racun_id', 'abc12', 'racunId'],
            'racun_id 12abc' => ['racun_id', '12abc', 'racunId'],
            'existing_copy_invoice_id x7' => ['existing_copy_invoice_id', 'x7', 'existingCopyInvoiceId'],
            // Tekst koji je numerički ali nije konačan broj (INF) daje null.
            'ukupan_iznos 1e999' => ['ukupan_iznos', '1e999', 'ukupanIznos'],
            'ukupan_iznos -1e999' => ['ukupan_iznos', '-1e999', 'ukupanIznos'],
            // Logička vrednost: samo true/false, 1/0, '1'/'0', 'true'/'false'.
            'success da' => ['success', 'da', 'success'],
            'success yes' => ['success', 'yes', 'success'],
            'duplikat ne' => ['duplikat', 'ne', 'duplikat'],
            'ukupan_iznos tekst' => ['ukupan_iznos', 'mnogo', 'ukupanIznos'],
            'ukupan_iznos bool' => ['ukupan_iznos', true, 'ukupanIznos'],
            'success tekst' => ['success', 'mozda', 'success'],
            'success broj 2' => ['success', 2, 'success'],
            'duplikat niz' => ['duplikat', [], 'duplikat'],
            'status niz' => ['status', ['x'], 'status'],
            'existing_copy_invoice_id tekst' => ['existing_copy_invoice_id', 'x', 'existingCopyInvoiceId'],
            'qr_url bool' => ['qr_url', false, 'qrUrl'],
        ];
    }

    /** Postojeći pozivi konstruktora sa pet argumenata rade kao pre (BC). */
    public function testKonstruktorSaPetArgumenataRadi(): void
    {
        $r = new FiskalizacijaResult('AB-1', 'QR', 5, 'REQ', 'poruka');

        self::assertSame('AB-1', $r->pfrBroj);
        self::assertSame('QR', $r->qrCode);
        self::assertSame(5, $r->racunId);
        self::assertSame('REQ', $r->requestId);
        self::assertSame('poruka', $r->poruka);
        self::assertNull($r->success);
        self::assertTrue($r->isSuccess());

        $parametri = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(FiskalizacijaResult::class, '__construct'))->getParameters(),
        );
        self::assertSame(['pfrBroj', 'qrCode', 'racunId', 'requestId', 'poruka'], array_slice($parametri, 0, 5));
        foreach ((new \ReflectionMethod(FiskalizacijaResult::class, '__construct'))->getParameters() as $i => $p) {
            if ($i >= 4) {
                self::assertTrue($p->isDefaultValueAvailable(), $p->getName() . ' ima podrazumevanu vrednost');
            }
        }
    }

    /** Docblock kaže da neuspeh nije izuzetak i upućuje na isSuccess(). */
    public function testDocblokNavodiDaNeuspehNijeIzuzetak(): void
    {
        $doc = (string) (new \ReflectionClass(FiskalizacijaResult::class))->getDocComment();

        self::assertStringContainsString('isSuccess()', $doc);
        self::assertStringContainsString('ne baca izuzetak', $doc);
    }
}
