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

        $this->assertSame('LAP-001', $data['sifra']);
        $this->assertSame('1234567890123', $data['gtin']);
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
