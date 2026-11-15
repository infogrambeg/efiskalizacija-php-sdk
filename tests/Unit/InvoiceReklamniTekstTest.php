<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use PHPUnit\Framework\TestCase;

/**
 * `setAdvertisementText()` šalje `reklamni_tekst` (server ga čuva i štampa na
 * računu). `setNote()` je zastareo: API korensko `napomena` ne čuva, ali ga
 * SDK i dalje šalje kao do sada (bez loma i bez preusmeravanja na reklamni
 * tekst, koji bi se odštampao na fiskalnom računu).
 *
 * @trace R4
 */
final class InvoiceReklamniTekstTest extends TestCase
{
    private static function racun(): Invoice
    {
        return Invoice::create()->addItem(new InvoiceItem(naziv: 'X', kolicina: 1, jedinicnaCena: 1, pdvStopa: 20));
    }

    public function testSetAdvertisementTextSaljeReklamniTekst(): void
    {
        $podaci = self::racun()->setAdvertisementText('Hvala na kupovini!')->toArray();

        self::assertSame('Hvala na kupovini!', $podaci['reklamni_tekst']);
        self::assertArrayNotHasKey('napomena', $podaci);
    }

    public function testBezReklamnogTekstaKljucNePostoji(): void
    {
        self::assertArrayNotHasKey('reklamni_tekst', self::racun()->toArray());
    }

    public function testSetNoteSeSaljeKaoDoSadaINePreusmerava(): void
    {
        $podaci = self::racun()->setNote('interna beleška')->toArray();

        self::assertSame('interna beleška', $podaci['napomena']);
        self::assertArrayNotHasKey('reklamni_tekst', $podaci);
    }

    public function testOboSeSaljuNezavisno(): void
    {
        $podaci = self::racun()->setNote('n')->setAdvertisementText('r')->toArray();

        self::assertSame('n', $podaci['napomena']);
        self::assertSame('r', $podaci['reklamni_tekst']);
    }

    public function testSetNoteJeZastareoIUpucujeNaSetAdvertisementText(): void
    {
        $doc = (string) (new \ReflectionMethod(Invoice::class, 'setNote'))->getDocComment();

        self::assertMatchesRegularExpression('/@deprecated\b/', $doc);
        self::assertStringContainsString('setAdvertisementText()', $doc);
        self::assertStringContainsString('ne čuva', $doc);
    }

    public function testSetAdvertisementTextDocblokKazeDaSeStampa(): void
    {
        $doc = (string) (new \ReflectionMethod(Invoice::class, 'setAdvertisementText'))->getDocComment();

        self::assertStringContainsString('`reklamni_tekst`', $doc);
        self::assertStringContainsString('štampa na računu', $doc);
        self::assertDoesNotMatchRegularExpression('/@deprecated\b/', $doc);
    }

    public function testMetodaVracaIstiObjekat(): void
    {
        $racun = self::racun();

        self::assertSame($racun, $racun->setAdvertisementText('x'));
    }
}
