<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Customer;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\DTO\Payment;
use Efiskalizacija\Enum\InvoiceType;
use Efiskalizacija\Enum\PaymentType;
use Efiskalizacija\Enum\TaxCategory;
use Efiskalizacija\Enum\TransactionType;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    public function testMinimalInvoiceToArray(): void
    {
        $invoice = Invoice::create()
            ->addItem(new InvoiceItem(
                naziv: 'Test artikal',
                kolicina: 1,
                jedinicnaCena: 100.00,
                pdvStopa: 20,
            ))
            ->setPaymentType(PaymentType::Kartica);

        $data = $invoice->toArray();

        $this->assertCount(1, $data['stavke']);
        $this->assertSame('prodaja', $data['tip_racuna']);
        $this->assertSame('sale', $data['tip_transakcije']);
        $this->assertSame('kartica', $data['nacin_placanja']);
    }

    public function testFullInvoiceToArray(): void
    {
        $invoice = Invoice::create()
            ->setInvoiceNumber('WC-2026-001')
            ->setInvoiceType(InvoiceType::Prodaja)
            ->setTransactionType(TransactionType::Sale)
            ->setPaymentType(PaymentType::Kartica)
            ->setCashier('Web Shop')
            ->setNote('Hvala na poverenju')
            ->setIdempotencyKey('wc:a1b2c3d4:1234')
            ->setCustomer(Customer::pravnoLice('123456789', 'Firma DOO'))
            ->addItem(new InvoiceItem(
                naziv: 'Laptop',
                kolicina: 1,
                jedinicnaCena: 89990.00,
                pdvStopa: 20,
                sifra: 'LAP-001',
                barcode: '1234567890123',
            ))
            ->addItem(new InvoiceItem(
                naziv: 'Mis',
                kolicina: 2,
                jedinicnaCena: 2500.00,
                pdvStopa: 20,
                rabatProcenat: 10.0,
            ));

        $data = $invoice->toArray();

        $this->assertSame('WC-2026-001', $data['broj_racuna']);
        $this->assertSame('prodaja', $data['tip_racuna']);
        $this->assertSame('sale', $data['tip_transakcije']);
        $this->assertSame('kartica', $data['nacin_placanja']);
        $this->assertSame('Web Shop', $data['kasir']);
        $this->assertSame('Hvala na poverenju', $data['napomena']);
        $this->assertCount(2, $data['stavke']);

        // Prva stavka
        $this->assertSame('Laptop', $data['stavke'][0]['naziv']);
        $this->assertSame('LAP-001', $data['stavke'][0]['sifra']);
        $this->assertSame('1234567890123', $data['stavke'][0]['gtin']);

        // Druga stavka
        $this->assertSame('Mis', $data['stavke'][1]['naziv']);
        $this->assertSame(10.0, $data['stavke'][1]['rabat_procenat']);

        // Kupac
        $this->assertSame('pravno_lice', $data['kupac']['tip']);
        $this->assertSame('123456789', $data['kupac']['identifikator']);

        // Idempotency key
        $this->assertSame('wc:a1b2c3d4:1234', $invoice->getIdempotencyKey());
    }

    public function testEmptyInvoiceThrows(): void
    {
        $this->expectException(\LogicException::class);

        Invoice::create()->toArray();
    }

    public function testSplitPayment(): void
    {
        $invoice = Invoice::create()
            ->addItem(new InvoiceItem(naziv: 'Test', kolicina: 1, jedinicnaCena: 10000, pdvStopa: 20))
            ->setSplitPayments([
                new Payment(PaymentType::Gotovina, 5000.00),
                new Payment(PaymentType::Kartica, 5000.00),
            ]);

        $data = $invoice->toArray();

        $this->assertIsArray($data['nacin_placanja']);
        $this->assertCount(2, $data['nacin_placanja']);
        $this->assertSame('gotovina', $data['nacin_placanja'][0]['tip']);
        $this->assertSame(5000.00, $data['nacin_placanja'][0]['iznos']);
    }

    public function testSplitPaymentMinimumTwo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Invoice::create()->setSplitPayments([
            new Payment(PaymentType::Gotovina, 5000.00),
        ]);
    }

    public function testSplitPaymentNoDuplicateTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplikat');

        Invoice::create()->setSplitPayments([
            new Payment(PaymentType::Kartica, 3000.00),
            new Payment(PaymentType::Kartica, 7000.00),
        ]);
    }

    public function testRefundInvoice(): void
    {
        $invoice = Invoice::create()
            ->setInvoiceType(InvoiceType::Prodaja)
            ->setTransactionType(TransactionType::Refund)
            ->setPaymentType(PaymentType::Kartica)
            ->setReferentDocument('AB12CD34-Ef5Gh6i7-101')
            ->addItem(new InvoiceItem(naziv: 'Refund', kolicina: 1, jedinicnaCena: 100, pdvStopa: 20));

        $data = $invoice->toArray();

        $this->assertSame('refund', $data['tip_transakcije']);
        $this->assertSame('AB12CD34-Ef5Gh6i7-101', $data['referentni_dokument']);
    }

    public function testAvansInvoice(): void
    {
        $invoice = Invoice::create()
            ->setInvoiceType(InvoiceType::Avans)
            ->setPaymentType(PaymentType::Virman)
            ->setCustomer(Customer::pravnoLice('123456789'))
            ->addItem(new InvoiceItem(naziv: 'Avans za laptop', kolicina: 1, jedinicnaCena: 50000, pdvStopa: 20));

        $data = $invoice->toArray();

        $this->assertSame('avans', $data['tip_racuna']);
    }

    public function testTaxCategoryOnZeroVat(): void
    {
        $invoice = Invoice::create()
            ->setPaymentType(PaymentType::Kartica)
            ->addItem(new InvoiceItem(
                naziv: 'Oslobodjena usluga',
                kolicina: 1,
                jedinicnaCena: 1000,
                pdvStopa: 0,
                pdvKategorija: TaxCategory::Oslobodjen,
            ))
            ->addItem(new InvoiceItem(
                naziv: 'Van PDV',
                kolicina: 1,
                jedinicnaCena: 2000,
                pdvStopa: 0,
                pdvKategorija: TaxCategory::NijeUPdv,
            ));

        $data = $invoice->toArray();

        $this->assertSame('oslobodjen', $data['stavke'][0]['pdv_kategorija']);
        $this->assertSame('nije_u_pdv', $data['stavke'][1]['pdv_kategorija']);
    }
}
