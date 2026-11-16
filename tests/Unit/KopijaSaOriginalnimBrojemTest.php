<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\Enum\InvoiceType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kopija sa brojem originala (od 1.2.0): uz `InvoiceType::Kopija` i
 * `setOriginalInvoiceNumber()` zahtev nosi `tip_racuna: "copy"` i
 * `original_broj_racuna` — oblik po kom server kopiju vezuje za broj
 * originala i odbija drugu kopiju istog broja (`errorCode`,
 * `existingCopyInvoiceId`). Bez broja originala zahtev je isti kao u 1.1.1.
 *
 * Da server baš ovaj oblik prepoznaje kao zaštićenu kopiju, dokazuje
 * unakrsni test glavnog repoa (`tests/Unit/SdkZahtevKljuceviTest.php`).
 *
 * @trace R4
 */
final class KopijaSaOriginalnimBrojemTest extends TestCase
{
    /**
     * Invoice sa `InvoiceType::Kopija` i brojem `SHOP-1`, serijalizovan klasama
     * iz `ec2b1d7` (pre ovog ciklusa); privatna svojstva nose NUL u imenu.
     */
    private static function serijalPreKopijeSaOriginalom(): string
    {
        $p = static fn (string $ime): string => 's:' . (strlen($ime) + 28) . ":\"\0Efiskalizacija\\DTO\\Invoice\0{$ime}\";";

        return 'O:26:"Efiskalizacija\DTO\Invoice":12:{'
            . $p('stavke') . 'a:1:{i:0;O:30:"Efiskalizacija\DTO\InvoiceItem":10:{s:5:"naziv";s:5:"Proba";'
            . 's:8:"kolicina";d:1;s:13:"jedinicnaCena";d:100;s:8:"pdvStopa";i:20;s:5:"sifra";N;s:7:"barcode";N;'
            . 's:12:"jedinicaMere";s:3:"kom";s:6:"popust";N;s:13:"rabatProcenat";N;s:13:"pdvKategorija";N;}}'
            . $p('splitPayments') . 'a:0:{}'
            . $p('nacinPlacanja') . 'N;'
            . $p('tipRacuna') . 'E:38:"Efiskalizacija\Enum\InvoiceType:Kopija";'
            . $p('tipTransakcije') . 'E:40:"Efiskalizacija\Enum\TransactionType:Sale";'
            . $p('brojRacuna') . 's:6:"SHOP-1";'
            . $p('kasir') . 'N;'
            . $p('kupac') . 'N;'
            . $p('referentniDokument') . 'N;'
            . $p('napomena') . 'N;'
            . $p('reklamniTekst') . 'N;'
            . $p('idempotencyKey') . 'N;'
            . '}';
    }

    /**
     * Isti račun (Kopija, broj `SHOP-1`, jedna stavka) serijalizovan klasama
     * objavljenog `v1.1.1`: `git archive v1.1.1 src`, pa `serialize()` računa
     * napravljenog javnim metodama te verzije, kodirano base64 (privatna
     * svojstva nose NUL). 11 svojstava: bez `reklamniTekst` i
     * `originalBrojRacuna`, koja su dodata u 1.2.0.
     */
    private static function serijalV111(): string
    {
        return (string) base64_decode(
            'TzoyNjoiRWZpc2thbGl6YWNpamFcRFRPXEludm9pY2UiOjExOntzOjM0OiIARWZpc2thbGl6YWNp'
            . 'amFcRFRPXEludm9pY2UAc3RhdmtlIjthOjE6e2k6MDtPOjMwOiJFZmlza2FsaXphY2lqYVxEVE9c'
            . 'SW52b2ljZUl0ZW0iOjEwOntzOjU6Im5heml2IjtzOjU6IlByb2JhIjtzOjg6ImtvbGljaW5hIjtk'
            . 'OjE7czoxMzoiamVkaW5pY25hQ2VuYSI7ZDoxMDA7czo4OiJwZHZTdG9wYSI7aToyMDtzOjU6InNp'
            . 'ZnJhIjtOO3M6NzoiYmFyY29kZSI7TjtzOjEyOiJqZWRpbmljYU1lcmUiO3M6Mzoia29tIjtzOjY6'
            . 'InBvcHVzdCI7TjtzOjEzOiJyYWJhdFByb2NlbmF0IjtOO3M6MTM6InBkdkthdGVnb3JpamEiO047'
            . 'fX1zOjQxOiIARWZpc2thbGl6YWNpamFcRFRPXEludm9pY2UAc3BsaXRQYXltZW50cyI7YTowOnt9'
            . 'czo0MToiAEVmaXNrYWxpemFjaWphXERUT1xJbnZvaWNlAG5hY2luUGxhY2FuamEiO047czozNzoi'
            . 'AEVmaXNrYWxpemFjaWphXERUT1xJbnZvaWNlAHRpcFJhY3VuYSI7RTozODoiRWZpc2thbGl6YWNp'
            . 'amFcRW51bVxJbnZvaWNlVHlwZTpLb3BpamEiO3M6NDI6IgBFZmlza2FsaXphY2lqYVxEVE9cSW52'
            . 'b2ljZQB0aXBUcmFuc2FrY2lqZSI7RTo0MDoiRWZpc2thbGl6YWNpamFcRW51bVxUcmFuc2FjdGlv'
            . 'blR5cGU6U2FsZSI7czozODoiAEVmaXNrYWxpemFjaWphXERUT1xJbnZvaWNlAGJyb2pSYWN1bmEi'
            . 'O3M6NjoiU0hPUC0xIjtzOjMzOiIARWZpc2thbGl6YWNpamFcRFRPXEludm9pY2UAa2FzaXIiO047'
            . 'czozMzoiAEVmaXNrYWxpemFjaWphXERUT1xJbnZvaWNlAGt1cGFjIjtOO3M6NDY6IgBFZmlza2Fs'
            . 'aXphY2lqYVxEVE9cSW52b2ljZQByZWZlcmVudG5pRG9rdW1lbnQiO047czozNjoiAEVmaXNrYWxp'
            . 'emFjaWphXERUT1xJbnZvaWNlAG5hcG9tZW5hIjtOO3M6NDI6IgBFZmlza2FsaXphY2lqYVxEVE9c'
            . 'SW52b2ljZQBpZGVtcG90ZW5jeUtleSI7Tjt9',
            true
        );
    }

    private static function kopija(): Invoice
    {
        return Invoice::create()
            ->setInvoiceType(InvoiceType::Kopija)
            ->addItem(new InvoiceItem(naziv: 'Proba', kolicina: 1, jedinicnaCena: 100.0, pdvStopa: 20));
    }

    public function testKopijaSaBrojemOriginalaSaljeCopyIBrojOriginala(): void
    {
        $data = self::kopija()->setOriginalInvoiceNumber('PP1-1-77')->toArray();

        self::assertSame('copy', $data['tip_racuna']);
        self::assertSame('PP1-1-77', $data['original_broj_racuna']);
    }

    public function testKopijaBezBrojaOriginalaJeKaoU111(): void
    {
        $data = self::kopija()->toArray();

        self::assertSame('kopija', $data['tip_racuna']);
        self::assertArrayNotHasKey('original_broj_racuna', $data);
        self::assertSame('kopija', InvoiceType::Kopija->value, 'vrednost enum-a je ista kao u 1.1.1');
    }

    public function testSetterVracaIstiRacun(): void
    {
        $racun = self::kopija();

        self::assertSame($racun, $racun->setOriginalInvoiceNumber('PP1-1-77'));
    }

    /**
     * Broj originala bez tipa kopije server ne čita (broj dodeljuje sam):
     * zahtev se ne šalje, umesto da tiho izgubi polje.
     *
     * @return array<string, array{InvoiceType}>
     */
    public static function tipoviKojiNisuKopija(): array
    {
        $tipovi = [];
        foreach (InvoiceType::cases() as $tip) {
            if ($tip !== InvoiceType::Kopija) {
                $tipovi[$tip->name] = [$tip];
            }
        }

        return $tipovi;
    }

    #[DataProvider('tipoviKojiNisuKopija')]
    public function testBrojOriginalaBezTipaKopijeSeOdbija(InvoiceType $tip): void
    {
        $racun = self::kopija()->setOriginalInvoiceNumber('PP1-1-77')->setInvoiceType($tip);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('InvoiceType::Kopija');

        $racun->toArray();
    }

    /**
     * Prazan broj i „0" server ne čita kao broj originala (`!empty`), pa bi
     * dodelio novi broj: setter ih odbija.
     *
     * @return array<string, array{string}>
     */
    public static function brojeviKojeServerNeCita(): array
    {
        return ['prazan' => [''], 'razmaci' => ['   '], 'tab i novi red' => ["\t\n"], 'nula' => ['0']];
    }

    #[DataProvider('brojeviKojeServerNeCita')]
    public function testPrazanBrojOriginalaSeOdbija(string $broj): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::kopija()->setOriginalInvoiceNumber($broj);
    }

    /**
     * Broj sa belinama na početku ili kraju sistem nikad ne dodeljuje; server
     * bi ga upisao doslovno, pa bi kopija pod `"2026-000077\n"` prošla pored
     * već izdate kopije broja `2026-000077`. Setter ga odbija.
     *
     * @return array<string, array{string}>
     */
    public static function brojeviSaBelinama(): array
    {
        return [
            'novi red na kraju' => ["2026-000077\n"],
            'razmak na početku' => [' 2026-000077'],
            'razmak na kraju' => ['2026-000077 '],
            'razmaci oko nule' => [' 00 '],
            'tab' => ["\t2026-000077"],
            'CR LF' => ["2026-000077\r\n"],
            'NUL na kraju' => ["2026-000077\0"],
            'vertikalni tab' => ["2026-000077\x0B"],
        ];
    }

    #[DataProvider('brojeviSaBelinama')]
    public function testBrojOriginalaSaBelinamaSeOdbija(string $broj): void
    {
        $this->expectException(\InvalidArgumentException::class);

        self::kopija()->setOriginalInvoiceNumber($broj);
    }

    public function testBrojOriginalaSeSaljeNeizmenjen(): void
    {
        $data = self::kopija()->setOriginalInvoiceNumber('2026-000077')->toArray();
        self::assertSame('2026-000077', $data['original_broj_racuna']);

        // Beline unutar broja nisu na početku ni na kraju: šalju se doslovno
        $data = self::kopija()->setOriginalInvoiceNumber('PP1 1 55')->toArray();
        self::assertSame('PP1 1 55', $data['original_broj_racuna']);
        self::assertSame('00', self::kopija()->setOriginalInvoiceNumber('00')->toArray()['original_broj_racuna']);
    }

    /**
     * Racun sačuvan kroz `serialize()` pre 1.2.0 se posle nadogradnje
     * deserijalizuje i šalje isto kao ranije (novo svojstvo ima podrazumevanu
     * vrednost na nivou svojstva).
     */
    public function testRacunSerijalizovanPreOvogIzdanjaSeSaljeKaoRanije(): void
    {
        $racun = unserialize(self::serijalPreKopijeSaOriginalom(), ['allowed_classes' => [Invoice::class, InvoiceItem::class]]);
        self::assertInstanceOf(Invoice::class, $racun);

        self::assertSame(
            [
                'stavke' => [['naziv' => 'Proba', 'kolicina' => 1.0, 'jedinicna_cena' => 100.0, 'pdv_stopa' => 20, 'jedinica_mere' => 'kom']],
                'tip_racuna' => 'kopija',
                'tip_transakcije' => 'sale',
                'broj_racuna' => 'SHOP-1',
            ],
            $racun->toArray()
        );
    }

    /**
     * Račun koji je serijalizovao objavljeni `v1.1.1` posle nadogradnje se
     * šalje bajt-isto kao što ga šalje 1.1.1 (telo iz `toArray()` te verzije).
     */
    public function testRacunSerijalizovanU111SeSaljeKaoU111(): void
    {
        $serijal = self::serijalV111();
        self::assertStringStartsWith('O:26:"Efiskalizacija\\DTO\\Invoice":11:{', $serijal, 'kontrola: 11 svojstava iz 1.1.1');
        self::assertStringNotContainsString('reklamniTekst', $serijal);
        self::assertStringNotContainsString('originalBrojRacuna', $serijal);

        $racun = unserialize($serijal, ['allowed_classes' => [Invoice::class, InvoiceItem::class]]);
        self::assertInstanceOf(Invoice::class, $racun);

        self::assertSame(
            '{"stavke":[{"naziv":"Proba","kolicina":1,"jedinicna_cena":100,"pdv_stopa":20,"jedinica_mere":"kom"}],'
                . '"tip_racuna":"kopija","tip_transakcije":"sale","broj_racuna":"SHOP-1"}',
            json_encode($racun->toArray(), JSON_UNESCAPED_UNICODE),
            'telo koje 1.1.1 šalje za isti račun'
        );
    }

    /** Kontrola serijala: isti oblik kao serialize() današnje klase, osim novog svojstva. */
    public function testSerijalJeOblikaDanasnjeKlase(): void
    {
        $danas = serialize(self::kopija()->setInvoiceNumber('SHOP-1'));

        self::assertStringContainsString("\0Efiskalizacija\\DTO\\Invoice\0originalBrojRacuna", $danas);
        $bezNovog = (string) preg_replace('/s:46:"\x00Efiskalizacija\\\\DTO\\\\Invoice\x00originalBrojRacuna";N;/', '', $danas);
        self::assertSame(self::serijalPreKopijeSaOriginalom(), str_replace(':13:{', ':12:{', $bezNovog));
    }
}
