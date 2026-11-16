<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Customer;
use PHPUnit\Framework\TestCase;

/**
 * Kupac se šalje pod ključevima koje server čita: `pib`, `jmbg` ili `jbkjs`
 * (BuyerId `10:PIB`, `11:JMBG`, `12:PIB:JBKJS`; PIB i JMBG server i čuva uz
 * račun). Do 1.1.1 SDK je slao `tip` (server ga ne čita) i `identifikator`,
 * koji server ne čuva, a za JBKJS ga odbija (traži 9 ili 13 cifara).
 * Izuzetak je `javniSektor()` bez `$pib`: on i dalje šalje oblik iz 1.1.1
 * ({@see JavniSektorKao111Test}).
 *
 * @trace R2
 */
final class CustomerTest extends TestCase
{
    public function testPravnoLice(): void
    {
        $customer = Customer::pravnoLice('123456789', 'Firma DOO', 'Knez Mihailova 10', 'Beograd');

        $data = $customer->toArray();

        $this->assertSame('123456789', $data['pib']);
        $this->assertSame('Firma DOO', $data['ime']);
        $this->assertSame('Knez Mihailova 10', $data['adresa']);
        $this->assertSame('Beograd', $data['mesto']);
        $this->assertSame(['ime', 'pib', 'adresa', 'mesto'], array_keys($data));
    }

    public function testPravnoLiceInvalidPib(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('9 cifara');

        Customer::pravnoLice('12345');
    }

    public function testFizickoLice(): void
    {
        $customer = Customer::fizickoLice('1234567890123', 'Petar Petrovic');

        $data = $customer->toArray();

        $this->assertSame(['ime' => 'Petar Petrovic', 'jmbg' => '1234567890123'], $data);
    }

    public function testFizickoLiceInvalidJmbg(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('13 cifara');

        Customer::fizickoLice('123');
    }

    /**
     * PURS (tehničko uputstvo ESIR/L-PFR, odeljak 9.8): šifra 12 je „PIB i
     * JBKJS kupca (PIB:JBKJS)", npr. `12:123456789:54321`. Server lepi `12:`
     * ispred `jbkjs` bez provere, pa SDK uz `$pib` šalje `jbkjs: "PIB:JBKJS"`.
     *
     * @trace R2
     */
    public function testJavniSektorSaPibomSaljePibIJbkjs(): void
    {
        $customer = Customer::javniSektor('54321', 'Ministarstvo', 'kabinet@ministarstvo.gov.rs', '123456789');

        $this->assertSame(
            ['ime' => 'Ministarstvo', 'jbkjs' => '123456789:54321', 'email' => 'kabinet@ministarstvo.gov.rs'],
            $customer->toArray()
        );
        $this->assertSame('123456789:54321', $customer->jbkjs);
        $this->assertNull($customer->pib, 'PIB javnog sektora ide samo u jbkjs');
        $this->assertNull($customer->jmbg);
    }

    /** Vrednost u obliku PIB:JBKJS bez `$pib` šalje se kao `jbkjs` (namerno, oznaka `12:`). @trace R2 */
    public function testJavniSektorSpojenOblikBezPibaIdeKaoJbkjs(): void
    {
        $this->assertSame(['jbkjs' => '123456789:54321'], Customer::javniSektor('123456789:54321')->toArray());
        $this->assertSame(
            ['ime' => 'Opstina', 'jbkjs' => '123456789:54321', 'email' => 'o@test.rs'],
            Customer::javniSektor('123456789:54321', 'Opstina', 'o@test.rs')->toArray()
        );
    }

    /**
     * Bez `$pib` kupac je bajt-isti kao u 1.1.1: svojstvo `jbkjs` nosi vrednost
     * bez izmene, a `toArray()` stari oblik `tip` + `identifikator` (bez
     * provere i bez uklanjanja belina). Poređenje sa samim tagom je u
     * {@see JavniSektorKao111Test}.
     *
     * @trace R2
     */
    public function testJavniSektorBezPibaJeOblikIz111(): void
    {
        $devet = Customer::javniSektor('123456789', 'Opstina', 'opstina@test.rs');
        $this->assertSame(
            ['ime' => 'Opstina', 'tip' => 'javni_sektor', 'identifikator' => '123456789', 'email' => 'opstina@test.rs'],
            $devet->toArray()
        );
        $this->assertSame('123456789', $devet->jbkjs);
        $this->assertNull($devet->pib);
        $this->assertNull($devet->jmbg);

        $trinaest = Customer::javniSektor('1234567890123', 'Opstina', 'opstina@test.rs');
        $this->assertSame(
            ['ime' => 'Opstina', 'tip' => 'javni_sektor', 'identifikator' => '1234567890123', 'email' => 'opstina@test.rs'],
            $trinaest->toArray()
        );

        $this->assertSame(['tip' => 'javni_sektor', 'identifikator' => "123456789\n"], Customer::javniSektor("123456789\n")->toArray());
        $this->assertSame(['tip' => 'javni_sektor', 'identifikator' => ''], Customer::javniSektor('')->toArray());
        $this->assertSame(['tip' => 'javni_sektor', 'identifikator' => '12345'], Customer::javniSektor('12345')->toArray());
    }

    /**
     * Bez `$pib` samo tačan oblik `^\d{9}:\d{5}\z` ide kao `jbkjs`; sve ostalo
     * (i slično spojenom obliku) ide u starom obliku, sa vrednošću bez izmene.
     *
     * @return array<string, array{string}>
     */
    public static function nijeSpojenOblik(): array
    {
        return [
            'slovo ispred' => ['x123456789:54321'],
            'bez dvotačke' => ['12345678954321'],
            'JBKJS 4 cifre' => ['123456789:5432'],
            'JBKJS 6 cifara' => ['123456789:543210'],
            'PIB 8 cifara' => ['12345678:54321'],
            'PIB 10 cifara' => ['1234567890:54321'],
            'novi red na kraju' => ["123456789:54321\n"],
            'razmak ispred' => [' 123456789:54321'],
            'obrnut' => ['54321:123456789'],
            'razmak unutra' => ['123456789: 54321'],
        ];
    }

    /** @trace R2 */
    #[\PHPUnit\Framework\Attributes\DataProvider('nijeSpojenOblik')]
    public function testJavniSektorBezPibaVanSpojenogOblikaIdeUStaromObliku(string $vrednost): void
    {
        $this->assertSame(['tip' => 'javni_sektor', 'identifikator' => $vrednost], Customer::javniSektor($vrednost)->toArray());
    }

    /**
     * Uz `$pib`: PIB tačno 9 cifara i JBKJS tačno 5 cifara, bez uklanjanja
     * belina (`\z`), inače izuzetak pre slanja.
     *
     * @return array<string, array{string, string}>
     */
    public static function neispravanJavniSektorSaPibom(): array
    {
        return [
            'JBKJS 4 cifre' => ['1234', '123456789'],
            'JBKJS 6 cifara' => ['123456', '123456789'],
            'PIB 8 cifara' => ['12345', '12345678'],
            'PIB 10 cifara' => ['12345', '1234567890'],
            'prazan PIB' => ['12345', ''],
            'prazan JBKJS' => ['', '123456789'],
            'novi red unutar JBKJS' => ["123\n45", '123456789'],
            'razmak unutar PIB-a' => ['12345', '1234 56789'],
            'novi red na kraju JBKJS' => ["54321\n", '123456789'],
            'novi red na kraju PIB-a' => ['54321', "123456789\n"],
            'razmak ispred PIB-a' => ['54321', ' 123456789'],
            'tab na kraju JBKJS' => ["54321\t", '123456789'],
            'NUL na kraju PIB-a' => ['54321', "123456789\0"],
            'spojen oblik uz PIB' => ['123456789:54321', '123456789'],
            'cifre van ASCII' => ['١٢٣٤٥', '123456789'],
            '9 cifara uz PIB' => ['123456789', '123456789'],
            '13 cifara uz PIB' => ['1234567890123', '123456789'],
        ];
    }

    /** @trace R2 */
    #[\PHPUnit\Framework\Attributes\DataProvider('neispravanJavniSektorSaPibom')]
    public function testJavniSektorSaNeispravnimPibomIliJbkjsSeOdbija(string $jbkjs, string $pib): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('PIB i JBKJS kupca');

        Customer::javniSektor($jbkjs, pib: $pib);
    }

    /**
     * Beline na krajevima PIB-a i JMBG-a se uklanjaju pre provere i ne šalju
     * se: `trim()` skida razmak, tab, novi red, CR, NUL i vertikalni tab.
     * 1.1.1 je `"123456789\n"` primao (`$` bez `D`), pa se taj poziv i dalje
     * izdaje, ali bez novog reda u upisu.
     *
     * @trace R2
     */
    public function testPibIJmbgSaBelinamaNaKrajevimaSeSaljuOcisceni(): void
    {
        $pib = Customer::pravnoLice("123456789\n", 'Firma');
        $this->assertSame(['ime' => 'Firma', 'pib' => '123456789'], $pib->toArray());
        $this->assertSame('123456789', $pib->pib);
        $this->assertSame('123456789', Customer::pravnoLice(" 123456789\t")->toArray()['pib']);
        $this->assertSame('123456789', Customer::pravnoLice("123456789\0")->toArray()['pib']);
        $this->assertSame('123456789', Customer::pravnoLice("\x0B123456789\r")->toArray()['pib']);

        $jmbg = Customer::fizickoLice("1234567890123\n", 'Petar');
        $this->assertSame(['ime' => 'Petar', 'jmbg' => '1234567890123'], $jmbg->toArray());
        $this->assertSame('1234567890123', $jmbg->jmbg);
        $this->assertSame('1234567890123', Customer::fizickoLice("\r\n1234567890123 ")->toArray()['jmbg']);
        $this->assertSame('1234567890123', Customer::fizickoLice("\0" . "1234567890123\x0B")->toArray()['jmbg']);
    }

    /** @return array<string, array{string, string}> */
    public static function neispravanPibIliJmbg(): array
    {
        return [
            'PIB 8 cifara' => ['pravnoLice', '12345678'],
            'PIB 10 cifara' => ['pravnoLice', '1234567890'],
            'PIB sa razmakom unutra' => ['pravnoLice', '1234 56789'],
            'PIB sa novim redom unutra' => ['pravnoLice', "12345678\n9"],
            'PIB van ASCII' => ['pravnoLice', '١٢٣٤٥٦٧٨٩'],
            'PIB samo beline' => ['pravnoLice', " \n"],
            // trim() ne skida form feed ni tvrdi razmak
            'PIB sa form feed na kraju' => ['pravnoLice', "123456789\f"],
            'PIB sa tvrdim razmakom na kraju' => ['pravnoLice', "123456789\u{00A0}"],
            'JMBG 12 cifara' => ['fizickoLice', '123456789012'],
            'JMBG 14 cifara' => ['fizickoLice', '12345678901234'],
            'JMBG sa razmakom unutra' => ['fizickoLice', '123456 7890123'],
            'JMBG sa form feed ispred' => ['fizickoLice', "\f1234567890123"],
        ];
    }

    /** @trace R2 */
    #[\PHPUnit\Framework\Attributes\DataProvider('neispravanPibIliJmbg')]
    public function testNeispravanPibIliJmbgSeOdbija(string $metoda, string $vrednost): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Customer::$metoda($vrednost);
    }

    public function testAnonimni(): void
    {
        $customer = Customer::anonimni('test@example.com');

        $data = $customer->toArray();

        $this->assertSame(['email' => 'test@example.com'], $data);
    }

    /**
     * Ključevi koje server ne čita (ili za JBKJS odbija) se ne šalju ni za
     * jedan oblik kupca osim `javniSektor()` bez `$pib` (oblik iz 1.1.1).
     */
    public function testNeSaljeTipNiIdentifikator(): void
    {
        foreach (
            [
                Customer::pravnoLice('123456789', 'Firma DOO'),
                Customer::fizickoLice('1234567890123', 'Petar'),
                Customer::javniSektor('12345', pib: '123456789'),
                Customer::javniSektor('123456789:54321'),
                Customer::anonimni('a@b.rs'),
            ] as $kupac
        ) {
            $data = $kupac->toArray();
            $this->assertArrayNotHasKey('tip', $data);
            $this->assertArrayNotHasKey('identifikator', $data);
        }
    }

    public function testPravnoLiceWithAllFields(): void
    {
        $customer = Customer::pravnoLice(
            pib: '123456789',
            ime: 'Firma DOO',
            adresa: 'Adresa 1',
            mesto: 'Beograd',
            email: 'firma@test.com',
            telefon: '+381601234567',
        );

        $data = $customer->toArray();

        $this->assertSame('firma@test.com', $data['email']);
        $this->assertSame('+381601234567', $data['telefon']);
    }
}
