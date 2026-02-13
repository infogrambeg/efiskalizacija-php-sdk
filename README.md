# eFiskalizacija PHP SDK

PHP SDK za [eFiskalizacija.cloud](https://efiskalizacija.cloud) - fiskalizacija računa u Srbiji (VSDC API).

## Zahtevi

- PHP >= 8.1
- ext-curl
- ext-json

## Instalacija

```bash
composer require infogrambeg/efiskalizacija-php-sdk
```

## Brzi početak

```php
use Efiskalizacija\EfiskalizacijaClient;
use Efiskalizacija\DTO\Invoice;
use Efiskalizacija\DTO\InvoiceItem;
use Efiskalizacija\DTO\Customer;
use Efiskalizacija\Enum\PaymentType;

// Kreiranje klijenta
$client = EfiskalizacijaClient::create($apiKey, $apiSecret);

// Kreiranje računa
$invoice = Invoice::create()
    ->setInvoiceNumber('SHOP-2026-001')
    ->setPaymentType(PaymentType::Kartica)
    ->setCashier('Web Shop')
    ->setCustomer(Customer::pravnoLice('123456789', 'Firma DOO'))
    ->addItem(new InvoiceItem(
        naziv: 'Laptop HP ProBook',
        kolicina: 1,
        jedinicnaCena: 85000.00,
        pdvStopa: 20,
        sifra: 'HP-PB-450',
    ));

// Fiskalizacija
$result = $client->fiskalizacija()->fiskalizuj($invoice);

echo $result->pfrBroj;   // "AB12CD34-Ef5Gh6i7-101"
echo $result->qrCode;    // URL za QR verifikaciju
echo $result->racunId;   // ID u sistemu
```

## API Metode

```php
// Fiskalizacija računa
$result = $client->fiskalizacija()->fiskalizuj($invoice);

// Status tenanta
$status = $client->status()->fetch();

// Lista računa
$list = $client->invoices()->list(limit: 20, offset: 0);

// PDF preuzimanje
$pdf = $client->pdf()->download($pfrBroj);
$client->pdf()->downloadToFile($pfrBroj, '/path/racun.pdf');

// Slanje na email
$client->email()->send($pfrBroj, 'kupac@example.com');

// Test (samo sandbox)
$test = $client->test()->run();
```

## Načini plaćanja

```php
use Efiskalizacija\Enum\PaymentType;

PaymentType::Gotovina   // Gotovina
PaymentType::Kartica    // Platna kartica
PaymentType::Virman     // Virmanski prenos
PaymentType::Vaucer     // Vaučer
PaymentType::Instant    // Instant plaćanje (IPS)
PaymentType::Drugo      // Drugo bezgotovinsko
```

### Split payment (podeljeno plaćanje)

```php
use Efiskalizacija\DTO\Payment;

$invoice->setSplitPayments([
    new Payment(PaymentType::Gotovina, 5000.00),
    new Payment(PaymentType::Kartica, 7800.00),
]);
```

## Kupac

```php
use Efiskalizacija\DTO\Customer;

// Pravno lice (PIB - 9 cifara)
Customer::pravnoLice('123456789', 'Firma DOO', 'Adresa 1', 'Beograd');

// Fizičko lice (JMBG - 13 cifara)
Customer::fizickoLice('1234567890123', 'Petar Petrović');

// Javni sektor (JBKJS)
Customer::javniSektor('12345', 'Ministarstvo');

// Anonimni kupac
Customer::anonimni('email@example.com');
```

## PDV kategorije (za 0% PDV)

```php
use Efiskalizacija\Enum\TaxCategory;

new InvoiceItem(
    naziv: 'Oslobođena usluga',
    kolicina: 1,
    jedinicnaCena: 5000.00,
    pdvStopa: 0,
    pdvKategorija: TaxCategory::Oslobodjen,   // Oslobođen PDV-a
);

new InvoiceItem(
    naziv: 'Van sistema PDV',
    kolicina: 1,
    jedinicnaCena: 3000.00,
    pdvStopa: 0,
    pdvKategorija: TaxCategory::NijeUPdv,     // Nije u sistemu PDV-a
);
```

## Popusti

```php
// Fiksni popust (iznos u RSD)
new InvoiceItem(
    naziv: 'Laptop',
    kolicina: 1,
    jedinicnaCena: 85000.00,
    pdvStopa: 20,
    popust: 5000.00,
);

// Procentualni popust (0-100%)
new InvoiceItem(
    naziv: 'Miš',
    kolicina: 2,
    jedinicnaCena: 4500.00,
    pdvStopa: 20,
    rabatProcenat: 10.0,
);
```

## Avansni računi

```php
use Efiskalizacija\Enum\InvoiceType;
use Efiskalizacija\Enum\TransactionType;

// 1. Avans prodaja
$avans = Invoice::create()
    ->setInvoiceType(InvoiceType::Avans)
    ->setPaymentType(PaymentType::Kartica)
    ->setCustomer(Customer::pravnoLice('123456789'))
    ->addItem(new InvoiceItem(naziv: 'Avans', kolicina: 1, jedinicnaCena: 50000, pdvStopa: 20));

// 2. Avans refundacija (referenca na poslednji avans)
$refund = Invoice::create()
    ->setInvoiceType(InvoiceType::Avans)
    ->setTransactionType(TransactionType::Refund)
    ->setReferentDocument($avansResult->pfrBroj)
    ->setPaymentType(PaymentType::Kartica)
    ->setCustomer(Customer::pravnoLice('123456789'))
    ->addItem(new InvoiceItem(naziv: 'Refundacija avansa', kolicina: 1, jedinicnaCena: 50000, pdvStopa: 20));

// 3. Konačni račun (referenca na avans refundaciju)
$final = Invoice::create()
    ->setReferentDocument($refundResult->pfrBroj)
    ->setPaymentType(PaymentType::Kartica)
    ->setCustomer(Customer::pravnoLice('123456789'))
    ->addItem(new InvoiceItem(naziv: 'Laptop Dell XPS', kolicina: 1, jedinicnaCena: 250000, pdvStopa: 20));
```

## Idempotency

```php
$invoice->setIdempotencyKey('wc:a1b2c3d4:1234');
```

Sprečava duplu fiskalizaciju istog računa. Preporučeni format: `{sistem}:{site_hash}:{order_id}`.

## Error handling

```php
use Efiskalizacija\Exception\AuthenticationException;
use Efiskalizacija\Exception\ValidationException;
use Efiskalizacija\Exception\RateLimitException;
use Efiskalizacija\Exception\ServerException;
use Efiskalizacija\Exception\NetworkException;

try {
    $result = $client->fiskalizacija()->fiskalizuj($invoice);
} catch (AuthenticationException $e) {
    // 401 - Neispravan API key ili HMAC potpis
} catch (ValidationException $e) {
    // 400/422 - Neispravni podaci
    $errors = $e->getErrors();
} catch (RateLimitException $e) {
    // 429 - Previše zahteva
    $retryAfter = $e->getRetryAfter(); // sekunde
} catch (ServerException $e) {
    // 500/503 - Greška na serveru (retry automatski)
} catch (NetworkException $e) {
    // Timeout, DNS (retry automatski)
}
```

## Retry logika

SDK automatski ponavlja zahteve za retryable greške:

| Greška                 | Retry | Strategija         |
| ---------------------- | ----- | ------------------ |
| 429 (Rate Limit)       | Da    | Exponential backoff |
| 502 (Bad Gateway)      | Da    | Exponential backoff |
| 503 (Unavailable)      | Da    | Exponential backoff |
| 504 (Timeout)          | Da    | Exponential backoff |
| Network error          | Da    | Exponential backoff |
| 400/422 (Validacija)   | Ne    | Permanentan fail   |
| 401/403 (Auth)         | Ne    | Permanentan fail   |

Default: 3 retry-a, delay 1s/2s/4s. Konfigurisano u `Config`.

## Webhook

```php
use Efiskalizacija\Webhook\WebhookPayload;

// U vašem webhook handler-u
$payload = WebhookPayload::fromJson(file_get_contents('php://input'));

if ($payload->isFiscalized()) {
    // Račun uspešno fiskalizovan
    $pfr = $payload->pfrBroj;
}

if ($payload->isFailed()) {
    // Fiskalizacija neuspešna
}
```

## Custom HTTP klijent

SDK koristi cURL po defaultu. Možete ubaciti svoj HTTP klijent (npr. za WordPress `wp_remote_request`):

```php
use Efiskalizacija\Http\HttpClientInterface;
use Efiskalizacija\Http\Response;

class WpHttpClient implements HttpClientInterface
{
    public function request(string $method, string $url, array $headers = [], string $body = ''): Response
    {
        $response = wp_remote_request($url, [
            'method' => $method,
            'headers' => $headers,
            'body' => $body,
            'timeout' => 30,
        ]);

        return new Response(
            wp_remote_retrieve_response_code($response),
            wp_remote_retrieve_body($response),
            wp_remote_retrieve_headers($response)->getAll(),
        );
    }
}

$client = new EfiskalizacijaClient($config, new WpHttpClient());
```

## Konfiguracija

```php
use Efiskalizacija\Config;

$config = new Config(
    apiKey: 'efisk_1_abc123...',
    apiSecret: 'your-64-char-secret',
    baseUrl: 'https://efiskalizacija.cloud',  // default
    timeout: 30,              // HTTP timeout (sekunde)
    connectTimeout: 10,       // Connection timeout (sekunde)
    maxRetries: 3,            // Broj retry pokušaja
    retryBaseDelayMs: 1000,   // Početni delay (ms)
    retryMultiplier: 2,       // Multiplier za exponential backoff
);

$client = new EfiskalizacijaClient($config);
```

## Testiranje

```bash
composer test
```

## Licenca

MIT
