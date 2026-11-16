<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Customer;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\DTO\TenantStatus;
use PHPUnit\Framework\TestCase;

/**
 * README za izmene zahteva u 1.2.0 (ciklus U1b2): šifra artikla
 * (`sifra_artikla`, granica 50: duža se ne šalje), ključevi kupca (javni
 * sektor `12:PIB:JBKJS`), kopija sa brojem originala i
 * `TenantStatus::$unlimitedInvoices` su u „Promene u 1.2.0" i
 * „Nadogradnja na 1.2.0", a tvrdnje teksta se slažu sa ponašanjem DTO-ova.
 *
 * Granicu 50 za šifru i broj originala veže za serversku konstantu unakrsni
 * test glavnog repoa (`tests/Unit/SdkZahtevKljuceviTest.php`).
 *
 * @trace R5
 */
final class Verzija120ZahtevDokumentacijaTest extends TestCase
{
    private static function readme(): string
    {
        $putanja = dirname(__DIR__, 2) . '/README.md';
        self::assertFileExists($putanja);

        return (string) file_get_contents($putanja);
    }

    private static function odeljak(string $naslov, string $sledeci): string
    {
        $readme = self::readme();
        $pocetak = strpos($readme, $naslov);
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($readme, $sledeci, $pocetak + strlen($naslov));
        self::assertNotFalse($kraj, $sledeci);

        return (string) preg_replace('/\s+/u', ' ', substr($readme, $pocetak, $kraj - $pocetak));
    }

    /** @trace R1 R2 R3 R4 */
    public function testPromeneNavodeIzmeneZahteva(): void
    {
        $promene = self::odeljak("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");

        foreach (
            [
                // R1
                '`sifra_artikla`', 'Šifra duža od 50 znakova se ne šalje i ne čuva (kao do 1.1.1)',
                // R2
                '`pib`', '`jmbg`', '`jbkjs`', '`tip` + `identifikator`',
                '`jbkjs: "PIB:JBKJS"`', '`12:PIB:JBKJS`', 'nov parametar `$pib` na kraju',
                'Bez `$pib` šalje kupca bajt-isto kao 1.1.1 (`tip` + `identifikator`)',
                // webhook posle R1/R2
                '`data.kupac.pib`', '`data.kupac.jmbg`', '`data.stavke[].sifra_artikla`',
                '`mesto` se šalje kao do sada, ali ga server ne čuva',
                // R4
                '`Invoice::setOriginalInvoiceNumber()`', '`tip_racuna: "copy"`', '`original_broj_racuna`',
                // R3
                '`TenantStatus::$unlimitedInvoices`', '`max_invoices_per_month: null`', '`TenantStatus::__unserialize()`',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $promene, $deo);
        }
    }

    /** @trace R1 R2 R3 R4 */
    public function testNadogradnjaNavodiIzmeneZahteva(): void
    {
        $nadogradnja = self::odeljak("### Nadogradnja na 1.2.0\n", "\n### Nadogradnja sa 1.0.x\n");

        foreach (
            [
                'duža od 50 znakova se ne šalje i ne čuva', '`sifra_artikla` umesto `sifra`', '`pib`, `jmbg` ili `jbkjs` umesto `tip` i `identifikator`',
                '`Customer::javniSektor()`', '`12:PIB:JBKJS`', "`Customer::javniSektor('54321', pib: '123456789')`",
                'postojeći pozivi (bez `$pib`) šalju isto telo kao u 1.1.1', 'Avans za javni sektor izdajte kroz `Customer::pravnoLice()`',
                '`setOriginalInvoiceNumber()`', '`unlimitedInvoices`',
                '`TenantStatus`', '1 kod `TenantStatus` i 2 kod `Invoice`', '`data.kupac.pib`',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $nadogradnja, $deo);
        }
    }

    /** Odeljak „Kopija računa" opisuje ponašanje koje DTO ima. @trace R4 */
    public function testOdeljakKopijeOpisujePonasanje(): void
    {
        $kopija = self::odeljak("\n## Kopija računa\n", "\n## Idempotency\n");

        foreach (
            [
                '->setInvoiceType(InvoiceType::Kopija)', '->setOriginalInvoiceNumber($original->brojRacuna)',
                "'copy_already_issued'", "'copy_in_progress'", 'existingCopyInvoiceId',
                'baca `LogicException` pre slanja', '`tip_racuna: "kopija"` (kao do 1.1.1)', 'najviše 50 znakova',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $kopija, $deo);
        }

        // Primer: original se proverava pre nego što se njegov broj koristi
        $proveraOriginala = strpos($kopija, 'if (!$original->isSuccess()) {');
        $brojOriginala = strpos($kopija, '->setOriginalInvoiceNumber($original->brojRacuna)');
        self::assertNotFalse($proveraOriginala, 'primer proverava isSuccess() originala');
        self::assertLessThan($brojOriginala, $proveraOriginala, 'provera originala ide pre setOriginalInvoiceNumber()');

        // Primer: rezultat kopije se proverava i za uspeh i za ostale neuspehe
        self::assertStringContainsString('if ($result->isSuccess()) {', $kopija);
        self::assertStringContainsString("throw new RuntimeException('Kopija nije izdata: ' . \$result->poruka);", $kopija);

        // Kopija ima sopstveni broj, različit od broja originala iz „Brzi početak"
        self::assertSame(1, preg_match("/\\\$kopija = Invoice::create\\(\\) ->setInvoiceNumber\\('([^']+)'\\)/", $kopija, $m), 'kopija ima setInvoiceNumber()');
        self::assertStringContainsString("->setInvoiceNumber('SHOP-2026-001')", self::readme(), 'kontrola: broj originala iz „Brzi početak"');
        self::assertNotSame('SHOP-2026-001', $m[1], 'broj kopije nije broj originala');
        foreach (
            [
                'Kopija ima sopstveni broj računa (`setInvoiceNumber()`)',
                '„broj_racuna je već upotrebljen za drugi tip računa“',
                '`EfiskalizacijaException` sa kodom 409',
            ] as $deo
        ) {
            self::assertStringContainsString($deo, $kopija, $deo);
        }
    }

    /** Prazna šifra se ne šalje, kao u tekstu. @trace R1 */
    public function testPraznaSifraJeUTekstu(): void
    {
        $sifra = self::odeljak("\n## Šifra artikla\n", "\n## PDV kategorije");
        self::assertStringContainsString('Prazna šifra (`\'\'`, npr. proizvod bez SKU-a) se ne šalje: stavka se čuva bez šifre, kao do 1.1.1.', $sifra);
        self::assertArrayNotHasKey('sifra_artikla', (new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 1, pdvStopa: 20, sifra: ''))->toArray());
    }

    /**
     * Javni sektor: tekst ne tvrdi da server čuva PIB uz račun za taj
     * kupac (serverska strana: `tests/Unit/SdkZahtevKljuceviTest.php`,
     * `kupac_pib` je NULL). @trace R2
     */
    public function testJavniSektorNeCuvaPibUzRacun(): void
    {
        $kupac = self::odeljak("\n## Kupac\n", "\n## Šifra artikla\n");
        self::assertStringContainsString('Za kupca iz `Customer::javniSektor()` (sa `$pib` i bez njega) server ne čuva PIB ni JBKJS uz račun: oni idu samo u oznaku kupca za Poresku upravu.', $kupac);
        self::assertStringContainsString('a PIB (`pravnoLice()`) i JMBG (`fizickoLice()`) čuva uz račun', $kupac);
        self::assertStringNotContainsString('a PIB i JMBG čuva uz račun', $kupac);
    }

    /** Tvrdnje odeljaka „Kupac" i „Šifra artikla" o ključevima su tačne. @trace R1 R2 */
    public function testKljuceviIzTekstaSuKljuceviDtoa(): void
    {
        $readme = (string) preg_replace('/\s+/u', ' ', self::readme());
        self::assertStringContainsString('`sifra` u `InvoiceItem` se šalje kao `sifra_artikla`', $readme);
        self::assertStringContainsString('Identifikacija kupca se šalje kao `pib`, `jmbg` ili `jbkjs`', $readme);

        $stavka = (new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 1, pdvStopa: 20, sifra: 'S-1'))->toArray();
        self::assertSame('S-1', $stavka['sifra_artikla']);
        self::assertSame(['pib'], array_values(array_intersect(['pib', 'jmbg', 'jbkjs'], array_keys(Customer::pravnoLice('123456789')->toArray()))));
        self::assertSame(['jmbg'], array_values(array_intersect(['pib', 'jmbg', 'jbkjs'], array_keys(Customer::fizickoLice('1234567890123')->toArray()))));
        self::assertSame(['jbkjs'], array_values(array_intersect(['pib', 'jmbg', 'jbkjs'], array_keys(Customer::javniSektor('54321', pib: '123456789')->toArray()))));
    }

    /**
     * Tvrdnje o javnom sektoru i šifri su tačne: oznaka je `12:PIB:JBKJS`,
     * primer iz README-a daje baš taj `jbkjs`, a šifra preko granice se ne
     * šalje (račun se ne obara). Kontrola: tačne rečenice postoje, pa
     * odsustvo starih nije posledica brisanja odeljka.
     *
     * @trace R1 R2
     */
    public function testJavniSektorISifraBezStarihTvrdnji(): void
    {
        $readme = (string) preg_replace('/\s+/u', ' ', self::readme());

        self::assertStringNotContainsString('`12:JBKJS`', $readme);
        self::assertStringNotContainsString('obara račun', $readme);
        self::assertStringNotContainsString('`Customer::javniSektor()` radi', $readme);
        self::assertStringNotContainsString("Customer::javniSektor('12345', 'Ministarstvo')", $readme);
        self::assertStringContainsString('`12:PIB:JBKJS`', $readme);
        self::assertStringContainsString('se ne šalje i ne čuva', $readme);

        self::assertStringContainsString("Customer::javniSektor('54321', 'Ministarstvo', pib: '123456789');", $readme);
        self::assertSame('123456789:54321', Customer::javniSektor('54321', 'Ministarstvo', pib: '123456789')->toArray()['jbkjs']);

        $preko = str_repeat('A', InvoiceItem::MAX_SIFRA_DUZINA + 1);
        self::assertArrayNotHasKey('sifra_artikla', (new InvoiceItem(naziv: 'A', kolicina: 1, jedinicnaCena: 1, pdvStopa: 20, sifra: $preko))->toArray());
        self::assertStringContainsString('najviše ' . InvoiceItem::MAX_SIFRA_DUZINA . ' znakova', $readme);
    }

    /**
     * Odeljak „Kupac" o javnom sektoru: oznaka primera, dužine PIB-a i JBKJS-a,
     * oblik iz 1.1.1 bez `$pib`, beline i avans — svaka tvrdnja uz ponašanje
     * koje je potvrđuje. Šta server radi sa oblikom iz 1.1.1 (9 cifara → `10:`,
     * 13 → `11:`, JBKJS od 5 cifara odbijen) dokazuje unakrsni test glavnog
     * repoa nad pravim validatorom (`tests/Unit/SdkZahtevKljuceviTest.php`).
     *
     * @trace R2
     */
    public function testOdeljakKupcaOJavnomSektoruJeTacan(): void
    {
        $kupac = self::odeljak("\n## Kupac\n", "\n## Šifra artikla\n");

        // Oznaka primera iz README-a
        self::assertStringContainsString("Customer::javniSektor('54321', 'Ministarstvo', pib: '123456789');", $kupac);
        self::assertStringContainsString('pa je oznaka za primer iznad `12:123456789:54321`.', $kupac);
        self::assertSame('12:123456789:54321', '12:' . Customer::javniSektor('54321', 'Ministarstvo', pib: '123456789')->toArray()['jbkjs']);

        // Dužine, bez belina i drugih znakova
        foreach (
            [
                'JBKJS 4' => ['5432', '123456789'], 'JBKJS 6' => ['543210', '123456789'],
                'PIB 8' => ['54321', '12345678'], 'PIB 10' => ['54321', '1234567890'],
                'novi red na kraju JBKJS' => ["54321\n", '123456789'], 'razmak ispred PIB-a' => ['54321', ' 123456789'],
                'crtica u PIB-u' => ['54321', '123-45678'],
            ] as $opis => [$jbkjs, $pib]
        ) {
            self::assertTrue(self::baca(static fn () => Customer::javniSektor($jbkjs, pib: $pib)), $opis);
        }
        self::assertFalse(self::baca(static fn () => Customer::javniSektor('54321', pib: '123456789')), 'kontrola');
        self::assertSame(['jbkjs' => '123456789:54321'], Customer::javniSektor('123456789:54321')->toArray(), 'spojen oblik se šalje isto');

        // Bez `$pib`: oblik iz 1.1.1, vrednost bez provere i bez uklanjanja belina
        foreach (['54321', '123456789', '1234567890123', "123456789\n", ' 54321', '1-2', ''] as $vrednost) {
            self::assertSame(['tip' => 'javni_sektor', 'identifikator' => $vrednost], Customer::javniSektor($vrednost)->toArray(), json_encode($vrednost));
        }

        // Beline kod pravnoLice/fizickoLice: tačno znakovi iz teksta
        self::assertSame('123456789', Customer::pravnoLice("123456789\n")->toArray()['pib']);
        $skida = [];
        for ($i = 0; $i < 256; $i++) {
            $z = chr($i);
            $pib = self::baca(static fn () => Customer::pravnoLice($z . '123456789' . $z));
            $jmbg = self::baca(static fn () => Customer::fizickoLice($z . '1234567890123' . $z));
            self::assertSame($pib, $jmbg, 'isti skup belina za PIB i JMBG: ' . bin2hex($z));
            if (!$pib) {
                $skida[] = $z;
            }
        }
        // Po rednom broju bajta: NUL, tab, novi red, vertikalni tab, CR, razmak
        self::assertSame(["\0", "\t", "\n", "\x0B", "\r", ' '], $skida, 'razmak, tab, novi red, CR, NUL i vertikalni tab');

        // Avans za javni sektor
        self::assertStringContainsString('„Kupac (PIB ili JMBG) je obavezan za avansne račune“', $kupac);
        self::assertStringContainsString('za avans koristite `Customer::pravnoLice()` sa PIB-om kupca', $kupac);
    }

    /**
     * Cele tvrdnje o javnom sektoru (odeljak „Kupac", „Promene u 1.2.0",
     * „Nadogradnja na 1.2.0"): tekst bloka je tačno ovaj, pa izmena bilo koje
     * reči obara test, a ponašanje iza svake rečenice proverava
     * {@see testOdeljakKupcaOJavnomSektoruJeTacan} i
     * {@see testPotpisJavnogSektoraJeKaoUTekstu}.
     *
     * @trace R2
     */
    public function testCeleTvrdnjeOJavnomSektoru(): void
    {
        $kupac = self::odeljakSirov("\n## Kupac\n", "\n## Šifra artikla\n");
        $promene = self::odeljakSirov("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");
        $nadogradnja = self::odeljakSirov("### Nadogradnja na 1.2.0\n", "\n### Nadogradnja sa 1.0.x\n");

        foreach (
            [
                [$kupac, 'Za kupca iz javnog sektora', 'Za kupca iz javnog sektora Poreska uprava, po tehničkom uputstvu PURS-a, traži PIB i JBKJS kupca: `Customer::javniSektor()` sa `$pib` šalje `jbkjs: "PIB:JBKJS"`, pa je oznaka za primer iznad `12:123456789:54321`. PIB mora imati tačno 9 cifara, a JBKJS tačno 5, bez belina i drugih znakova; inače se baca `InvalidArgumentException` pre slanja. Vrednost već u obliku `PIB:JBKJS` bez `$pib` (`Customer::javniSektor(\'123456789:54321\')`) šalje se isto.'],
                [$kupac, 'Bez `$pib`', 'Bez `$pib` `Customer::javniSektor()` šalje kupca kao 1.1.1: `tip: "javni_sektor"` i `identifikator` sa vrednošću kakvu ste prosledili, bez provere i bez uklanjanja belina. Server takav račun izdaje ili odbija kao do sada: vrednost od 9 cifara izdaje sa oznakom `10:PIB`, vrednost od 13 cifara sa oznakom `11:JMBG`, a JBKJS od 5 cifara odbija. Za kupca iz javnog sektora navedite i PIB.'],
                [$kupac, 'Kod `Customer::pravnoLice()`', 'Kod `Customer::pravnoLice()` i `Customer::fizickoLice()` razmak, tab, novi red, CR, NUL i vertikalni tab na krajevima PIB-a, odnosno JMBG-a, uklanjaju se pre provere i ne šalju se: `Customer::pravnoLice("123456789\n")` šalje `pib: "123456789"`.'],
                [$kupac, 'Avansni račun za kupca iz javnog sektora', 'Avansni račun za kupca iz javnog sektora sa JBKJS-om server još ne prima („Kupac (PIB ili JMBG) je obavezan za avansne račune“): za avans koristite `Customer::pravnoLice()` sa PIB-om kupca.'],
                [$promene, '- **Javni sektor po uputstvu PURS-a.**', '- **Javni sektor po uputstvu PURS-a.** `Customer::javniSektor()` ima nov parametar `$pib` na kraju. Sa njim šalje PIB i JBKJS kupca (`jbkjs: "PIB:JBKJS"`, oznaka `12:PIB:JBKJS`); PIB mora imati tačno 9, a JBKJS tačno 5 cifara, inače se baca `InvalidArgumentException` pre slanja. Bez `$pib` šalje kupca bajt-isto kao 1.1.1 (`tip` + `identifikator`), pa server takav račun izdaje ili odbija kao do sada; jedini izuzetak je vrednost u obliku `PIB:JBKJS`, koja se šalje kao `jbkjs`. Do 1.1.1 server je račun za javni sektor sa JBKJS-om odbijao (`identifikator` od 5 cifara). Kod `pravnoLice()` i `fizickoLice()` beline na krajevima PIB-a i JMBG-a se uklanjaju pre provere i ne šalju se.'],
                [$promene, '- **Kupac pod ključevima koje server čita.**', '- **Kupac pod ključevima koje server čita.** `Customer::toArray()` šalje `pib`, `jmbg` ili `jbkjs` umesto `tip` + `identifikator` (osim `Customer::javniSektor()` bez `$pib`): server sada čuva PIB i JMBG kupca uz račun. Oznaka kupca za Poresku upravu za PIB i JMBG je ista kao ranije. `mesto` se šalje kao do sada, ali ga server ne čuva.'],
                [$nadogradnja, '- **`Customer::javniSektor()`**', '- **`Customer::javniSektor()`**: postojeći pozivi (bez `$pib`) šalju isto telo kao u 1.1.1, pa server račun izdaje ili odbija kao do sada. Za oznaku kupca `12:PIB:JBKJS` dodajte PIB kupca kao nov parametar na kraju: `Customer::javniSektor(\'54321\', pib: \'123456789\')`; do 1.1.1 server je račun sa JBKJS-om odbijao. Avans za javni sektor izdajte kroz `Customer::pravnoLice()`.'],
            ] as [$odeljak, $pocetak, $ocekivano]
        ) {
            self::assertSame($ocekivano, self::blok($odeljak, $pocetak), $pocetak);
        }

        // Ponašanje iza rečenica Promena/Nadogradnje koje odeljak „Kupac" ne pokriva
        self::assertSame(['tip', 'identifikator'], array_keys(Customer::javniSektor('54321')->toArray()));
        self::assertSame(['jbkjs'], array_keys(Customer::javniSektor('54321', pib: '123456789')->toArray()));
        self::assertTrue(self::baca(static fn () => Customer::javniSektor('54321', pib: '12345678')));
    }

    /**
     * „Potpisi postojećih javnih metoda su isti, osim `Customer::javniSektor(...)`":
     * potpis u tekstu je potpis metode (refleksija).
     *
     * @trace R2
     */
    public function testPotpisJavnogSektoraJeKaoUTekstu(): void
    {
        $parametri = array_map(
            static function (\ReflectionParameter $p): string {
                $tip = (string) $p->getType();
                $podrazumevano = $p->isDefaultValueAvailable() ? ' = ' . strtolower(var_export($p->getDefaultValue(), true)) : '';

                return "{$tip} \${$p->getName()}{$podrazumevano}";
            },
            (new \ReflectionMethod(Customer::class, 'javniSektor'))->getParameters(),
        );
        $potpis = 'Customer::javniSektor(' . implode(', ', $parametri) . ')';
        self::assertSame('Customer::javniSektor(string $jbkjs, ?string $ime = null, ?string $email = null, ?string $pib = null)', $potpis);

        $promene = self::odeljak("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");
        self::assertStringContainsString('Potpisi postojećih javnih metoda su isti, osim `' . $potpis . '` (nov opcioni parametar `$pib` na kraju)', $promene);
    }

    /** Pozitivna kontrola čitača blokova: nalazi blok i u pasusu i u listi, i vidi izmenu reči. */
    public function testCitacBlokovaVidiIzmenu(): void
    {
        $tekst = "Uvod.\n\nPrvi pasus\nu dva reda.\n\n- **A.** stavka\n  nastavak.\n- **B.** druga.\n";
        self::assertSame('Prvi pasus u dva reda.', self::blok($tekst, 'Prvi pasus'));
        self::assertSame('- **A.** stavka nastavak.', self::blok($tekst, '- **A.**'));
        self::assertNotSame('- **A.** stavka nastavak.', self::blok(str_replace('stavka', 'tvrdnja', $tekst), '- **A.**'));
    }

    private static function baca(callable $poziv): bool
    {
        try {
            $poziv();
        } catch (\InvalidArgumentException) {
            return true;
        }

        return false;
    }

    private static function odeljakSirov(string $naslov, string $sledeci): string
    {
        $readme = self::readme();
        $pocetak = strpos($readme, $naslov);
        self::assertNotFalse($pocetak, $naslov);
        $kraj = strpos($readme, $sledeci, $pocetak + strlen($naslov));
        self::assertNotFalse($kraj, $sledeci);

        return substr($readme, $pocetak, $kraj - $pocetak);
    }

    /**
     * Pasus ili stavka liste koja počinje zadatim tekstom (tačno jedna), sa
     * belinama svedenim na jedan razmak.
     */
    private static function blok(string $odeljak, string $pocetak): string
    {
        $nadjeni = [];
        foreach (preg_split('/\n[ \t]*\n|\n(?=- )/', $odeljak) ?: [] as $deo) {
            $deo = trim((string) preg_replace('/\s+/u', ' ', $deo));
            if (str_starts_with($deo, $pocetak)) {
                $nadjeni[] = $deo;
            }
        }
        self::assertCount(1, $nadjeni, 'blok koji počinje sa: ' . $pocetak);

        return $nadjeni[0];
    }

    /** Svako javno svojstvo `TenantStatus` dodato posle 1.1.1 je u „Promene u 1.2.0". @trace R3 */
    public function testNovoSvojstvoStatusaJeUPromenama(): void
    {
        $promene = self::odeljak("### Promene u 1.2.0\n", "\n### Promene u 1.1.1\n");
        $staro = [
            'naziv', 'pib', 'environment', 'currentMonthInvoices', 'maxInvoicesPerMonth',
            'totalInvoices', 'thisMonth', 'successRate', 'version',
        ];
        $nova = array_diff(
            array_map(
                static fn (\ReflectionProperty $p): string => $p->getName(),
                (new \ReflectionClass(TenantStatus::class))->getProperties(\ReflectionProperty::IS_PUBLIC),
            ),
            $staro,
        );

        self::assertSame(['unlimitedInvoices'], array_values($nova));
        foreach ($nova as $svojstvo) {
            self::assertStringContainsString("`{$svojstvo}`", $promene, $svojstvo);
        }
    }
}
