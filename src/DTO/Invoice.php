<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

use Efiskalizacija\Enum\InvoiceType;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Enum\TransactionType;

final class Invoice
{
    /** @var InvoiceItem[] */
    private array $stavke = [];

    /** @var Payment[] */
    private array $splitPayments = [];

    private ?PaymentType $nacinPlacanja = null;
    private InvoiceType $tipRacuna = InvoiceType::Prodaja;
    private TransactionType $tipTransakcije = TransactionType::Sale;
    private ?string $brojRacuna = null;
    private ?string $kasir = null;
    private ?Customer $kupac = null;
    private ?string $referentniDokument = null;
    private ?string $napomena = null;
    private ?string $reklamniTekst = null;
    private ?string $idempotencyKey = null;
    private ?string $originalBrojRacuna = null;

    /**
     * Vrednost `tip_racuna` po kojoj server kopiju vezuje za broj originala;
     * `InvoiceType::Kopija` (`kopija`) bez broja originala server ne vezuje.
     */
    private const TIP_KOPIJE_SA_ORIGINALOM = 'copy';

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function addItem(InvoiceItem $item): self
    {
        $this->stavke[] = $item;
        return $this;
    }

    public function setPaymentType(PaymentType $type): self
    {
        $this->nacinPlacanja = $type;
        $this->splitPayments = [];
        return $this;
    }

    /**
     * Split payment - podeljeno placanje.
     *
     * @param Payment[] $payments Minimum 2 elementa, zbir = ukupan iznos
     */
    public function setSplitPayments(array $payments): self
    {
        if (count($payments) < 2) {
            throw new \InvalidArgumentException(
                'Split payment zahteva minimum 2 nacina placanja'
            );
        }

        $types = [];
        foreach ($payments as $payment) {
            if (!$payment instanceof Payment) {
                throw new \InvalidArgumentException(
                    'Svi elementi moraju biti instance Payment klase'
                );
            }
            if (in_array($payment->tip, $types, true)) {
                throw new \InvalidArgumentException(
                    'Duplikat nacina placanja: ' . $payment->tip->value
                );
            }
            $types[] = $payment->tip;
        }

        $this->splitPayments = $payments;
        $this->nacinPlacanja = null;
        return $this;
    }

    public function setInvoiceType(InvoiceType $type): self
    {
        $this->tipRacuna = $type;
        return $this;
    }

    public function setTransactionType(TransactionType $type): self
    {
        $this->tipTransakcije = $type;
        return $this;
    }

    public function setInvoiceNumber(string $brojRacuna): self
    {
        $this->brojRacuna = $brojRacuna;
        return $this;
    }

    public function setCashier(string $kasir): self
    {
        $this->kasir = $kasir;
        return $this;
    }

    public function setCustomer(Customer $kupac): self
    {
        $this->kupac = $kupac;
        return $this;
    }

    /**
     * Broj originalnog računa za kopiju (`original_broj_racuna`), od 1.2.0.
     *
     * Važi samo uz `setInvoiceType(InvoiceType::Kopija)`. Zahtev tada nosi
     * `tip_racuna: "copy"`: kopija dobija broj originala (umesto novog broja
     * sa servera), a server pre slanja Poreskoj upravi odbija drugu kopiju
     * istog broja — rezultat sa `errorCode` `copy_already_issued` (uz
     * `existingCopyInvoiceId`, ID već izdate kopije) ili `copy_in_progress`
     * (druga kopija istog broja je upravo u obradi). Broj originala je broj
     * računa koji je dodelio sistem (`FiskalizacijaResult::$brojRacuna`
     * originala), ne broj iz `setInvoiceNumber()`. Broj duži od 50 znakova
     * server odbija: kopija se ne izdaje (`isSuccess()` je false).
     *
     * Bez ovog poziva kopija se šalje kao do 1.1.1 (`tip_racuna: "kopija"`):
     * server joj dodeljuje novi broj i druge kopije ne proverava.
     *
     * @throws \InvalidArgumentException za prazan broj (ili samo beline) i za
     *                                   „0", koje server ne čita kao broj originala,
     *                                   i za broj sa belinama na početku ili kraju
     *                                   (razmak, tab, novi red, NUL): sistem takav
     *                                   broj ne dodeljuje, a server bi ga upisao
     *                                   doslovno, pa bi kopija prošla pored već
     *                                   izdate kopije istog broja
     */
    public function setOriginalInvoiceNumber(string $brojOriginala): self
    {
        if (trim($brojOriginala) === '' || $brojOriginala === '0') {
            throw new \InvalidArgumentException(
                'Broj originalnog racuna ne sme biti prazan ni "0" (server ga ne prepoznaje kao broj originala)'
            );
        }

        if (trim($brojOriginala) !== $brojOriginala) {
            throw new \InvalidArgumentException(
                'Broj originalnog racuna ne sme imati beline na pocetku ni na kraju (koristite FiskalizacijaResult::$brojRacuna originala)'
            );
        }

        $this->originalBrojRacuna = $brojOriginala;
        return $this;
    }

    public function setReferentDocument(string $pfrBroj): self
    {
        $this->referentniDokument = $pfrBroj;
        return $this;
    }

    /**
     * Šalje korensko polje `napomena`, koje se ne čuva uz račun: ne vidi se
     * na računu, u listi računa ni u admin panelu.
     *
     * @deprecated od 1.2.0 — za tekst na računu koristite
     *             setAdvertisementText() (`reklamni_tekst`); vrednost se i
     *             dalje šalje kao do sada, ali se ne čuva uz račun.
     */
    public function setNote(string $napomena): self
    {
        $this->napomena = $napomena;
        return $this;
    }

    /**
     * Reklamni tekst računa (polje `reklamni_tekst`): server ga čuva i
     * štampa na računu (PDF, odeljak „Napomena“). Ne šaljite interne
     * beleške — vidi ih kupac.
     */
    public function setAdvertisementText(string $tekst): self
    {
        $this->reklamniTekst = $tekst;
        return $this;
    }

    /**
     * Lokalna oznaka računa; ne šalje se serveru i NE štiti od duplog računa.
     *
     * Zaštita od duplog fiskalnog računa je {@see self::setInvoiceNumber()}
     * (polje `broj_racuna`): server prepoznaje već upisan račun sa istim
     * brojem i vraća ga umesto novog, a SDK samo takav POST sme sam da ponovi
     * kad ishod zahteva nije poznat. Prozor ostaje kad se obrada na serveru
     * prekine ili ne uspe posle fiskalizacije (pad servera, istek odgovora
     * Poreske uprave): server tada račun nije upisao, pa ponovljen zahtev sa
     * istim brojem izdaje drugi račun.
     *
     * @deprecated od 1.2.0 — koristite setInvoiceNumber(); vrednost se i dalje
     *             čuva (i vraća {@see self::getIdempotencyKey()}), ali se ne šalje.
     */
    public function setIdempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;
        return $this;
    }

    /**
     * Vrednost zadata kroz {@see self::setIdempotencyKey()}; ne šalje se
     * serveru.
     *
     * @deprecated od 1.2.0 — zaštita od duplog računa je setInvoiceNumber()
     *             (`broj_racuna`).
     */
    public function getIdempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    /**
     * @return InvoiceItem[]
     */
    public function getItems(): array
    {
        return $this->stavke;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (empty($this->stavke)) {
            throw new \LogicException('Racun mora imati bar jednu stavku');
        }

        $data = [
            'stavke' => array_map(fn(InvoiceItem $item) => $item->toArray(), $this->stavke),
            'tip_racuna' => $this->tipRacuna->value,
            'tip_transakcije' => $this->tipTransakcije->value,
        ];

        if ($this->originalBrojRacuna !== null) {
            // Server broj originala čita samo uz tip kopije; bez njega bi
            // polje tiho otpalo, a račun dobio novi broj.
            if ($this->tipRacuna !== InvoiceType::Kopija) {
                throw new \LogicException(
                    'setOriginalInvoiceNumber() vazi samo uz InvoiceType::Kopija (tip racuna: '
                    . $this->tipRacuna->value . ')'
                );
            }
            $data['tip_racuna'] = self::TIP_KOPIJE_SA_ORIGINALOM;
            $data['original_broj_racuna'] = $this->originalBrojRacuna;
        }

        if (!empty($this->splitPayments)) {
            $data['nacin_placanja'] = array_map(
                fn(Payment $p) => $p->toArray(),
                $this->splitPayments
            );
        } elseif ($this->nacinPlacanja !== null) {
            $data['nacin_placanja'] = $this->nacinPlacanja->value;
        }

        if ($this->brojRacuna !== null) {
            $data['broj_racuna'] = $this->brojRacuna;
        }
        if ($this->kasir !== null) {
            $data['kasir'] = $this->kasir;
        }
        if ($this->kupac !== null) {
            $data['kupac'] = $this->kupac->toArray();
        }
        if ($this->referentniDokument !== null) {
            $data['referentni_dokument'] = $this->referentniDokument;
        }
        if ($this->napomena !== null) {
            $data['napomena'] = $this->napomena;
        }
        if ($this->reklamniTekst !== null) {
            $data['reklamni_tekst'] = $this->reklamniTekst;
        }

        return $data;
    }
}
