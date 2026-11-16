<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\Enum\TaxCategory;
use PHPUnit\Framework\TestCase;

final class InvoiceItemTest extends TestCase
{
    public function testMinimalItem(): void
    {
        $item = new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 100.00,
            pdvStopa: 20,
        );

        $data = $item->toArray();

        $this->assertSame('Test', $data['naziv']);
        $this->assertSame(1.0, $data['kolicina']);
        $this->assertSame(100.00, $data['jedinicna_cena']);
        $this->assertSame(20, $data['pdv_stopa']);
        $this->assertSame('kom', $data['jedinica_mere']);
        $this->assertArrayNotHasKey('sifra_artikla', $data);
        $this->assertArrayNotHasKey('sifra', $data);
        $this->assertArrayNotHasKey('gtin', $data);
        $this->assertArrayNotHasKey('popust', $data);
        $this->assertArrayNotHasKey('rabat_procenat', $data);
    }

    public function testItemWithBarcode(): void
    {
        $item = new InvoiceItem(
            naziv: 'Laptop',
            kolicina: 1,
            jedinicnaCena: 89990.00,
            pdvStopa: 20,
            sifra: 'LAP-001',
            barcode: '1234567890123',
        );

        $data = $item->toArray();

        // @trace R1 — server čuva `stavke[].sifra_artikla`; `sifra` se ne šalje
        $this->assertSame('LAP-001', $data['sifra_artikla']);
        $this->assertArrayNotHasKey('sifra', $data);
        $this->assertSame('1234567890123', $data['gtin']);
    }

    private static function sifra(string $sifra): array
    {
        return (new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 10.0, pdvStopa: 20, sifra: $sifra))->toArray();
    }

    /**
     * Granica šifre je granica servera (`InvoiceValidator`, kolona
     * `stavke_racuna.sifra_artikla` VARCHAR(50)); unakrsni test glavnog repoa
     * je veže za serversku konstantu.
     *
     * @trace R1
     */
    public function testGranicaSifreJeGranicaServera(): void
    {
        $this->assertSame(50, InvoiceItem::MAX_SIFRA_DUZINA);
    }

    /**
     * Šifra duža od granice se ne šalje (kao do 1.1.1: račun se izdaje, šifra
     * se ne čuva); server bi inače odbio ceo račun. Broje se znakovi, ne
     * bajtovi, a razmaci na kraju se ne broje (VARCHAR ih odseca bez greške).
     *
     * @trace R1
     */
    public function testSifraPrekoGraniceSeNeSalje(): void
    {
        $granica = InvoiceItem::MAX_SIFRA_DUZINA;

        $this->assertSame(str_repeat('Š', $granica), self::sifra(str_repeat('Š', $granica))['sifra_artikla'], 'na granici (znakovi, ne bajtovi)');
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat('Š', $granica + 1)));
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat('A', $granica + 1)));
        $this->assertArrayNotHasKey('sifra', self::sifra(str_repeat('A', $granica + 1)));
        $this->assertSame(str_repeat('A', $granica) . '   ', self::sifra(str_repeat('A', $granica) . '   ')['sifra_artikla'], 'razmaci na kraju se ne broje');
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat('A', $granica) . "\t"), 'tabulator se broji');
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(' ' . str_repeat('A', $granica)), 'razmak na početku se broji');
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat('A', $granica) . "\n"), 'novi red na kraju se broji');
        // Broje se kodne tačke (kao mb_strlen na serveru), ne grafemi: „e" + U+0301 su dva znaka
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat("e\xCC\x81", intdiv($granica, 2) + 1)), 'kombinujući znak se broji');
        $this->assertSame(str_repeat("e\xCC\x81", intdiv($granica, 2)), self::sifra(str_repeat("e\xCC\x81", intdiv($granica, 2)))['sifra_artikla']);

        // Neispravan UTF-8 se broji po bajtovima (nikad manje od znakova)
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(str_repeat("\xFF", $granica + 1)));
        $this->assertSame(str_repeat("\xFF", $granica), self::sifra(str_repeat("\xFF", $granica))['sifra_artikla']);

        $stavka = new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 10.0, pdvStopa: 20, sifra: str_repeat('A', $granica + 1));
        $this->assertSame(str_repeat('A', $granica + 1), $stavka->sifra, 'svojstvo zadržava šifru');
    }

    /**
     * Šifra sa NUL znakom se ne šalje: server takvu šifru odbija zajedno sa
     * celim računom, a 1.1.1 je račun izdavao bez šifre.
     *
     * @trace R1
     */
    public function testSifraSaNulZnakomSeNeSalje(): void
    {
        foreach (["AB\0CD", "\0", "LAP-001\0", "\0LAP-001"] as $sifra) {
            $this->assertArrayNotHasKey('sifra_artikla', self::sifra($sifra), bin2hex($sifra));
            $this->assertArrayNotHasKey('sifra', self::sifra($sifra), bin2hex($sifra));
        }
        $this->assertSame('LAP-001', self::sifra('LAP-001')['sifra_artikla'], 'kontrola');
    }

    /**
     * Prazna šifra (npr. WooCommerce `get_sku()` za proizvod bez SKU-a) se ne
     * šalje: server bi upisao prazan string, a 1.1.1 je stavku čuvao bez šifre
     * (NULL). Šifra od samih razmaka se šalje (server je prima i čuva).
     *
     * @trace R1
     */
    public function testPraznaSifraSeNeSalje(): void
    {
        $this->assertArrayNotHasKey('sifra_artikla', self::sifra(''));
        $this->assertArrayNotHasKey('sifra', self::sifra(''));
        $this->assertSame('', (new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 10.0, pdvStopa: 20, sifra: ''))->sifra, 'svojstvo zadržava šifru');
        $this->assertSame(' ', self::sifra(' ')['sifra_artikla'], 'kontrola: razmak nije prazna šifra');
        $this->assertSame('0', self::sifra('0')['sifra_artikla'], 'kontrola: „0" nije prazna šifra');
    }

    public function testItemWithFixedDiscount(): void
    {
        $item = new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 1000.00,
            pdvStopa: 20,
            popust: 200.00,
        );

        $data = $item->toArray();

        $this->assertSame(200.00, $data['popust']);
        $this->assertArrayNotHasKey('rabat_procenat', $data);
    }

    public function testItemWithPercentDiscount(): void
    {
        $item = new InvoiceItem(
            naziv: 'Test',
            kolicina: 2,
            jedinicnaCena: 500.00,
            pdvStopa: 20,
            rabatProcenat: 15.0,
        );

        $data = $item->toArray();

        $this->assertSame(15.0, $data['rabat_procenat']);
        $this->assertArrayNotHasKey('popust', $data);
    }

    public function testBothDiscountsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Popust i rabat_procenat');

        new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 1000.00,
            pdvStopa: 20,
            popust: 100.00,
            rabatProcenat: 10.0,
        );
    }

    public function testNegativeDiscountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('negativan');

        new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 1000.00,
            pdvStopa: 20,
            popust: -50.00,
        );
    }

    public function testDiscountExceedsAmountThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('veci od iznosa');

        new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 100.00,
            pdvStopa: 20,
            popust: 200.00,
        );
    }

    public function testRabatOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('izmedju 0 i 100');

        new InvoiceItem(
            naziv: 'Test',
            kolicina: 1,
            jedinicnaCena: 100.00,
            pdvStopa: 20,
            rabatProcenat: 150.0,
        );
    }

    public function testTaxCategoryIncluded(): void
    {
        $item = new InvoiceItem(
            naziv: 'Usluga',
            kolicina: 1,
            jedinicnaCena: 5000.00,
            pdvStopa: 0,
            pdvKategorija: TaxCategory::NijeUPdv,
        );

        $data = $item->toArray();

        $this->assertSame('nije_u_pdv', $data['pdv_kategorija']);
    }
}
