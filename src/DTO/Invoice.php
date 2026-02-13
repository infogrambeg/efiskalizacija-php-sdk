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
    private ?string $idempotencyKey = null;

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

    public function setReferentDocument(string $pfrBroj): self
    {
        $this->referentniDokument = $pfrBroj;
        return $this;
    }

    public function setNote(string $napomena): self
    {
        $this->napomena = $napomena;
        return $this;
    }

    /**
     * Idempotency key sprecava duplu fiskalizaciju.
     * Preporuceni format: "wc:{site_hash}:{order_id}"
     */
    public function setIdempotencyKey(string $key): self
    {
        $this->idempotencyKey = $key;
        return $this;
    }

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

        return $data;
    }
}
