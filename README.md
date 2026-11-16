# eFiskalizacija PHP SDK

PHP SDK za [eFiskalizacija.cloud](https://efiskalizacija.cloud) - fiskalizacija računa u Srbiji (VSDC API).

## Zahtevi

- PHP >= 8.1
- ext-curl
- ext-json

## Instalacija

```bash
composer require infogrambeg/efiskalizacija-php-sdk:^1.2
```

Trenutna verzija: **1.2.0** (potpis zahteva v2 od 1.1.0, vidi „Potpis zahteva (HMAC v2)“).
Verzija 1.1.0 i novije zahtevaju server sa podrškom za potpis v2 — `efiskalizacija.cloud` i
`staging.efiskalizacija.cloud` je imaju od 2026-11-13.

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

if (!$result->isSuccess()) {
    // Neuspeh koji server vrati kao rezultat ne baca izuzetak
    echo $result->poruka;    // razlog (npr. odgovor Poreske uprave)
    echo $result->errorCode; // šifra greške ili null (vidi ispod)
    return;
}

echo $result->pfrBroj;        // "AB12CD34-Ef5Gh6i7-101"
echo $result->qrUrl;          // adresa za proveru računa na portalu Poreske uprave
echo $result->qrCode;         // QR kod kao base64 PNG
echo $result->racunId;        // ID u sistemu
echo $result->klijentskiBroj; // "SHOP-2026-001" (broj iz setInvoiceNumber())
var_dump($result->duplikat);  // true: vraćen već izdat račun sa istim brojem
```

Rezultat (`FiskalizacijaResult`) nosi i `success`, `status`, `brojRacuna` (broj koji je
dodelio sistem), `ukupanIznos`, `pfrDatumVreme`, `esirOznaka`, `errorCode` i
`existingCopyInvoiceId`. `errorCode` (`copy_already_issued` / `copy_in_progress`) i
`existingCopyInvoiceId` (uz `copy_already_issued`) server popunjava samo za kopiju računa
poslatu sa `tip_racuna: "copy"` i `original_broj_racuna`. SDK takav zahtev šalje kad uz
`InvoiceType::Kopija` postavite `setOriginalInvoiceNumber()` (vidi „Kopija računa“); bez
broja originala su `null`. `duplikat` je `null`
kad zahtev nije nosio broj računa. Izuzetak se baca samo za HTTP grešku (vidi „Error handling“).

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

// Javni sektor (PIB i JBKJS kupca)
Customer::javniSektor('54321', 'Ministarstvo', pib: '123456789');

// Anonimni kupac
Customer::anonimni('email@example.com');
```

Identifikacija kupca se šalje kao `pib`, `jmbg` ili `jbkjs` (osim kod
`Customer::javniSektor()` bez `$pib`, vidi ispod); server od nje pravi oznaku kupca za
Poresku upravu (`10:PIB`, `11:JMBG`, `12:PIB:JBKJS`), a PIB (`pravnoLice()`) i JMBG
(`fizickoLice()`) čuva uz račun, kao i ime, adresu, email i telefon. Za kupca iz
`Customer::javniSektor()` (sa `$pib` i bez njega) server ne čuva PIB ni JBKJS uz račun: oni
idu samo u oznaku kupca za Poresku upravu. Mesto kupca (`mesto`) se šalje, ali ga server ne
čuva (ne vidi se na računu ni u listi računa).

Za kupca iz javnog sektora Poreska uprava, po tehničkom uputstvu PURS-a, traži PIB i JBKJS
kupca: `Customer::javniSektor()` sa `$pib` šalje `jbkjs: "PIB:JBKJS"`, pa je oznaka za
primer iznad `12:123456789:54321`. PIB mora imati tačno 9 cifara, a JBKJS tačno 5, bez
belina i drugih znakova; inače se baca `InvalidArgumentException` pre slanja. Vrednost već
u obliku `PIB:JBKJS` bez `$pib` (`Customer::javniSektor('123456789:54321')`) šalje se isto.

Bez `$pib` `Customer::javniSektor()` šalje kupca kao 1.1.1: `tip: "javni_sektor"` i
`identifikator` sa vrednošću kakvu ste prosledili, bez provere i bez uklanjanja belina.
Server takav račun izdaje ili odbija kao do sada: vrednost od 9 cifara izdaje sa oznakom
`10:PIB`, vrednost od 13 cifara sa oznakom `11:JMBG`, a JBKJS od 5 cifara odbija. Za kupca
iz javnog sektora navedite i PIB.

Kod `Customer::pravnoLice()` i `Customer::fizickoLice()` razmak, tab, novi red, CR, NUL i
vertikalni tab na krajevima PIB-a, odnosno JMBG-a, uklanjaju se pre provere i ne šalju se:
`Customer::pravnoLice("123456789\n")` šalje `pib: "123456789"`.

Avansni račun za kupca iz javnog sektora sa JBKJS-om server još ne prima („Kupac (PIB ili
JMBG) je obavezan za avansne račune“): za avans koristite `Customer::pravnoLice()` sa PIB-om
kupca.

## Šifra artikla

`sifra` u `InvoiceItem` se šalje kao `sifra_artikla` i čuva uz stavku računa. Šifra može
imati najviše 50 znakova (`InvoiceItem::MAX_SIFRA_DUZINA`, prati granicu servera; razmaci
na kraju se ne broje). Šifra duža od 50 znakova se ne šalje i ne čuva, kao do 1.1.1: račun
se izdaje bez šifre te stavke, jer bi server inače odbio ceo račun. Prazna šifra (`''`,
npr. proizvod bez SKU-a) se ne šalje: stavka se čuva bez šifre, kao do 1.1.1. Svojstvo
`sifra` zadržava celu vrednost.

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

$avansResult = $client->fiskalizacija()->fiskalizuj($avans);
if (!$avansResult->isSuccess()) {
    // Bez fiskalizovanog avansa nema reference za sledeći korak: stanite
    throw new RuntimeException('Avans nije fiskalizovan: ' . $avansResult->poruka);
}

// 2. Avans refundacija (referenca na poslednji avans)
$refund = Invoice::create()
    ->setInvoiceType(InvoiceType::Avans)
    ->setTransactionType(TransactionType::Refund)
    ->setReferentDocument($avansResult->pfrBroj)
    ->setPaymentType(PaymentType::Kartica)
    ->setCustomer(Customer::pravnoLice('123456789'))
    ->addItem(new InvoiceItem(naziv: 'Refundacija avansa', kolicina: 1, jedinicnaCena: 50000, pdvStopa: 20));

$refundResult = $client->fiskalizacija()->fiskalizuj($refund);
if (!$refundResult->isSuccess()) {
    throw new RuntimeException('Refundacija avansa nije fiskalizovana: ' . $refundResult->poruka);
}

// 3. Konačni račun (referenca na avans refundaciju)
$final = Invoice::create()
    ->setReferentDocument($refundResult->pfrBroj)
    ->setPaymentType(PaymentType::Kartica)
    ->setCustomer(Customer::pravnoLice('123456789'))
    ->addItem(new InvoiceItem(naziv: 'Laptop Dell XPS', kolicina: 1, jedinicnaCena: 250000, pdvStopa: 20));

$finalResult = $client->fiskalizacija()->fiskalizuj($final);
if (!$finalResult->isSuccess()) {
    throw new RuntimeException('Konačni račun nije fiskalizovan: ' . $finalResult->poruka);
}
```

Neuspeh fiskalizacije nije izuzetak (vidi „Brzi početak“), pa posle svakog koraka proverite
`isSuccess()` i stanite pre sledećeg `setReferentDocument()`: neuspeo korak ima prazan
`pfrBroj`, pa sledeći račun ne bi imao ispravnu referencu.

## Kopija računa

```php
use Efiskalizacija\Enum\InvoiceType;

$original = $client->fiskalizacija()->fiskalizuj($invoice); // račun iz „Brzi početak“
if (!$original->isSuccess()) {
    throw new RuntimeException('Original nije fiskalizovan: ' . $original->poruka);
}

$kopija = Invoice::create()
    ->setInvoiceNumber('SHOP-2026-001-K1') // sopstveni broj kopije, ne broj originala
    ->setInvoiceType(InvoiceType::Kopija)
    ->setOriginalInvoiceNumber($original->brojRacuna) // broj koji je sistem dodelio originalu
    ->setReferentDocument($original->pfrBroj)
    ->setPaymentType(PaymentType::Kartica)
    ->addItem(new InvoiceItem(naziv: 'Laptop', kolicina: 1, jedinicnaCena: 85000.00, pdvStopa: 20));

$result = $client->fiskalizacija()->fiskalizuj($kopija);

if ($result->isSuccess()) {
    // kopija je izdata: $result->pfrBroj
} elseif ($result->errorCode === 'copy_already_issued') {
    // kopija ovog broja je već izdata: $result->existingCopyInvoiceId
} elseif ($result->errorCode === 'copy_in_progress') {
    // druga kopija istog broja je upravo u obradi; pokušajte ponovo kasnije
} else {
    throw new RuntimeException('Kopija nije izdata: ' . $result->poruka);
}
```

Kopija ima sopstveni broj računa (`setInvoiceNumber()`), kao svaki fiskalni dokument: isti
broj kao original server odbija („broj_racuna je već upotrebljen za drugi tip računa“,
`EfiskalizacijaException` sa kodom 409). Original se proverava pre kopije: `brojRacuna` i
`pfrBroj` originala koristite samo kad je njegov `isSuccess()` true.

Uz `setOriginalInvoiceNumber()` (od 1.2.0) zahtev nosi `tip_racuna: "copy"` i
`original_broj_racuna`: kopija dobija broj originala, a server drugu kopiju istog broja
odbija pre slanja Poreskoj upravi (`errorCode`, vidi „Brzi početak“). Broj originala je
`brojRacuna` iz rezultata originala (ne broj iz `setInvoiceNumber()`), najviše 50 znakova.
`setOriginalInvoiceNumber()` važi samo uz `InvoiceType::Kopija`: za drugi tip `fiskalizuj()`
baca `LogicException` pre slanja. Bez broja originala `InvoiceType::Kopija` se šalje kao
`tip_racuna: "kopija"` (kao do 1.1.1): server dodeljuje nov broj i druge kopije ne proverava.

## Idempotency

```php
$invoice->setInvoiceNumber('SHOP-2026-001'); // jedinstven broj iz vašeg sistema, npr. broj narudžbine
```

Zaštita od duplog fiskalnog računa je broj računa (`setInvoiceNumber()`, polje
`broj_racuna`): server prepoznaje već upisan račun sa istim brojem i vraća ga umesto
novog. Zato SDK `POST /fiskalizacija` sa nepraznim brojem sme sam da ponovi i kad se ne
zna da li je prvi zahtev obrađen (vidi „Retry logika“), a isto važi i kad vi ponovite
poziv sa istim brojem.

Zaštita ima prozor: server račun fiskalizuje pre nego što ga upiše. Kad se obrada na
serveru prekine ili ne uspe posle fiskalizacije (pad servera, istek odgovora Poreske
uprave, posle kog server vraća neuspeh), račun postoji kod Poreske uprave, ali ga server
nije upisao, pa se ne vidi ni u `invoices()->list()` ni u admin panelu, a ponovljen zahtev
sa istim brojem izdaje drugi račun. Provera (`invoices()->list()` ili admin panel) nalazi
samo upisan račun i korisna je pre svega za zahtev bez broja. Zato, kad ishod ostane
nepoznat i posle poslednjeg pokušaja, sa brojem računa ili bez njega, proverite da li je
račun izdat, a kad ga provera ne nađe, pre novog slanja obratite se podršci.

Bez broja računa svaki zahtev koji server obradi daje nov račun. Od 1.2.0 SDK takav
zahtev ne ponavlja kad ishod nije poznat (prekid veze posle slanja, 502, 503, 504), nego baca
izuzetak: pre novog slanja proverite da li je račun izdat (npr. `invoices()->list()` ili
admin panel).

`setIdempotencyKey()` / `getIdempotencyKey()` su od 1.2.0 zastareli (`@deprecated`):
ključ se čuva u objektu, ali se ne šalje serveru i ne štiti od duplog računa.

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
    // 5xx - Greška na serveru (posle automatskih ponavljanja, vidi „Retry logika“)
} catch (NetworkException $e) {
    // Timeout, DNS, konekcija. $e->wasRequestSent(): false = zahtev nije poslat,
    // true ili null = možda je obrađen — bez broja računa proverite ishod pre novog slanja
}
```

## Potpis zahteva (HMAC v2)

Od verzije **1.1.0** SDK klijent svaki zahtev potpisuje potpisom **v2**
(`HmacSigner::getAuthHeadersV2()`) i šalje zaglavlje `X-Signature-Version: 2`:

```
stringToSign = "v2:" + X-Timestamp + METHOD + requestTarget + SHA256(body)
X-Signature  = Base64(HMAC-SHA256(apiSecret, stringToSign))
```

`requestTarget` je putanja API-ja **sa query stringom**, tačno kako se šalje (npr.
`/api/multitenant.php/pdf?pfr=ABC-1&download=1`), bez dekodiranja, sortiranja i
normalizacije. Server proverava potpis nad sirovim `REQUEST_URI`, pa zahtev kome je query
izmenjen posle potpisa dobija `401`.

Query string SDK kodira po RFC 3986 (razmak je `%20`, `~` ostaje nekodiran), a `?` dodaje
samo uz neprazan query. Taj oblik Guzzle, Symfony HttpClient i WordPress HTTP API šalju
neizmenjen.

- Ako koristite sopstveni HTTP klijent (vidi „Custom HTTP klijent“), on mora poslati
  `$url` bajt-identično kako ga je SDK predao — bez ponovnog kodiranja ili preuređivanja
  parametara — i proslediti sva zaglavlja, uključujući `X-Signature-Version`.
- Bazni URL (`Config` `baseUrl`) treba da bude **koren hosta** (npr.
  `https://efiskalizacija.cloud`), jer je server uvek na `/api/multitenant.php`. Putanja
  baznog URL-a se NE potpisuje: bazni URL sa putanjom (npr. `https://primer.rs/efisk`)
  radi samo iza gateway-a koji taj prefiks skida pre servera.
- Starije verzije potpisuju v1 (bez zaglavlja `X-Signature-Version`). Server v1 proverava
  nad putanjom **bez** query stringa. Samo **1.0.2** pre potpisa odseca query, pa prolazi i
  za GET sa parametrima (`/pdf`, `/invoice`); **1.0.0 i 1.0.1** potpisuju putanju sa
  query-jem, pa takav GET dobija `401`. Server i dalje prima v1 na fiskalnim rutama, ali za
  nove integracije koristite 1.1.0 ili noviju.

### Promene u 1.2.0

- **Rezultat fiskalizacije po stvarnim poljima servera.** `FiskalizacijaResult::fromArray()`
  čita `qr_kod` (rezervno `qr_code`) i `message` (rezervno `poruka`): do 1.1.1 su `qrCode` i
  `poruka` uvek bili `null`. Nova svojstva (na kraju konstruktora, sa podrazumevanom
  vrednošću `null`): `success`, `status`, `brojRacuna`, `klijentskiBroj`, `duplikat`,
  `ukupanIznos`, `pfrDatumVreme`, `qrUrl`, `esirOznaka`, `errorCode`,
  `existingCopyInvoiceId`, i metoda `isSuccess()`. Neuspeh koji server vrati kao rezultat
  (`success: false`) i dalje je rezultat, ne izuzetak. Vrednost u neočekivanom tipu (npr.
  broj kao tekst) prevodi se kad je to jednoznačno, a inače je `null`.
- **Webhook po stvarnom telu.** `WebhookPayload::fromArray()` čita polja iz `data`
  (`invoice_id` → `racunId`, `pfr_broj`, `ukupan_iznos` → `iznos`), uz rezervu na stare
  ključeve sa vrha. Nova svojstva: `klijentskiBroj`, `brojRacuna` (`invoice_number`),
  `success`, `datumIzdavanja`, `errorMessage`, `timestamp`. `status` je izveden iz
  `success` (`success` / `error`) kad ga telo ne nosi. `isFiscalized()` i `isFailed()`
  su bez promene. Za račun izdat sa 1.2.0 `invoice.fiscalized` nosi i `data.kupac.pib` /
  `data.kupac.jmbg` i `data.stavke[].sifra_artikla` (server ih čita iz kolona upisanih uz
  račun; do 1.1.1 PIB i JMBG nisu bili u `data.kupac`, a `sifra_artikla` je bio `null`);
  čitaju se iz `rawData`.
- **`Invoice::setAdvertisementText()`** šalje `reklamni_tekst`: server ga čuva i štampa na
  računu. `Invoice::setNote()` je `@deprecated` (korensko `napomena` se ne čuva uz račun); šalje
  se kao do sada.
- **Šifra artikla se čuva.** `InvoiceItem` šalje `sifra` kao `sifra_artikla`, ključ koji
  server čuva uz stavku (do 1.1.1 `sifra`, koju server nije čitao, pa se šifra gubila).
  Šifra duža od 50 znakova se ne šalje i ne čuva (kao do 1.1.1), pa račun i dalje prolazi
  (vidi „Šifra artikla“).
- **Kupac pod ključevima koje server čita.** `Customer::toArray()` šalje `pib`, `jmbg` ili
  `jbkjs` umesto `tip` + `identifikator` (osim `Customer::javniSektor()` bez `$pib`): server
  sada čuva PIB i JMBG kupca uz račun. Oznaka kupca za Poresku upravu za PIB i JMBG je ista
  kao ranije. `mesto` se šalje kao do sada, ali ga server ne čuva.
- **Javni sektor po uputstvu PURS-a.** `Customer::javniSektor()` ima nov parametar `$pib` na
  kraju. Sa njim šalje PIB i JBKJS kupca (`jbkjs: "PIB:JBKJS"`, oznaka `12:PIB:JBKJS`); PIB
  mora imati tačno 9, a JBKJS tačno 5 cifara, inače se baca `InvalidArgumentException` pre
  slanja. Bez `$pib` šalje kupca bajt-isto kao 1.1.1 (`tip` + `identifikator`), pa server
  takav račun izdaje ili odbija kao do sada; jedini izuzetak je vrednost u obliku
  `PIB:JBKJS`, koja se šalje kao `jbkjs`. Do 1.1.1 server je račun za javni sektor sa
  JBKJS-om odbijao (`identifikator` od 5 cifara). Kod `pravnoLice()` i `fizickoLice()`
  beline na krajevima PIB-a i JMBG-a se uklanjaju pre provere i ne šalju se.
- **Kopija sa brojem originala.** `Invoice::setOriginalInvoiceNumber()` uz
  `InvoiceType::Kopija` šalje `tip_racuna: "copy"` i `original_broj_racuna`, pa server
  odbija drugu kopiju istog broja i popunjava `errorCode` / `existingCopyInvoiceId` (vidi
  „Kopija računa“). Bez nje kopija se šalje kao u 1.1.1.
- **`TenantStatus::$unlimitedInvoices`**: `true` kad nalog nema mesečni limit računa
  (server šalje `max_invoices_per_month: null`); `maxInvoicesPerMonth` je tada i dalje 0.

- **Ponavljanje zahteva čiji ishod nije poznat.** SDK sam ponavlja samo zahtev bezbedan
  za ponavljanje: GET i `POST /fiskalizacija` čije telo nosi `broj_racuna` koji posle
  `trim` nije prazan (server prepoznaje već upisan račun sa istim brojem i vraća ga umesto
  novog; ostaje prozor kad se obrada na serveru prekine ili ne uspe posle fiskalizacije:
  server tada račun nije upisao, pa ponovljen zahtev sa istim brojem izdaje drugi račun, vidi
  „Idempotency“). Ostali
  zahtevi (`fiskalizuj()` bez broja računa, `email()->send()`, `test()->run()`, POST iz
  sopstvene podklase `AbstractApi` na drugu rutu ili bez broja računa) šalju se tačno
  jednom kad se ne zna da li ih je server obradio: posle 502, 503 i 504 i posle mrežne
  greške za koju nije sigurno da zahtev nije poslat. Izuzetak se baca odmah, bez pauze,
  uz WARNING (metoda, putanja bez query-ja, pokušaj, status ili klasa izuzetka — bez
  tela, ključa i potpisa). Na 429 (server ga vraća pre obrade) i kad zahtev sigurno nije
  poslat, ponavljaju se kao ranije. 503 ne vraća samo aplikacija: Apache ga vraća i kad
  je PHP zahtev već obradio, a odgovor se izgubio (prekinut radnik, istek roka obrade).
  Do 1.1.1 SDK je ponavljao svaki zahtev, pa je `fiskalizuj()` bez broja računa posle
  isteka roka mogao da izda dva računa.
- `NetworkException::wasRequestSent(): ?bool` — `false` samo kad je sigurno da zahtev
  nije poslat, `true` kad jeste, `null` kad se ne zna. Konstruktor ima nov opcioni
  parametar `requestSent` (podrazumevano `null`), postojeći pozivi se ne menjaju.
  `CurlHttpClient` postavlja `false` samo kad cURL nije poslao nijedan bajt zahteva
  (`CURLINFO_REQUEST_SIZE` = 0: zatvoren port, nerazrešiv host, neuspela TLS veza), inače
  `true`.
- `#[\SensitiveParameter]` nose i parametri sa zaglavljima i telom zahteva duž cele
  putanje slanja: `FiskalizacijaApi::fiskalizuj`, `EmailApi::send`, `CurlHttpClient::request`,
  telo u `AbstractApi`, ključ i telo u `HmacSigner::sign`, `signV2`, `getAuthHeaders` i
  `getAuthHeadersV2`. Na PHP ≥ 8.2 trag `NetworkException` iz `CurlHttpClient`
  (`getTrace()`, `(string) $e`) više ne sadrži API ključ, potpis ni telo zahteva. Na PHP
  8.1 atribut nema efekta (uključite `zend.exception_ignore_args=On`). Sopstveni
  `HttpClientInterface` atribut ne nasleđuje — dodajte ga na svoje parametre.
- `Invoice::setIdempotencyKey()` / `getIdempotencyKey()` su `@deprecated`: ključ se ni
  ranije nije slao serveru. Zaštita od duplog računa je `setInvoiceNumber()`.
- Potpis zahteva (v2) je bez promene. Potpisi postojećih javnih metoda su isti, osim
  `Customer::javniSektor(string $jbkjs, ?string $ime = null, ?string $email = null, ?string $pib = null)`
  (nov opcioni parametar `$pib` na kraju) i konstruktora koji su dobili nove opcione
  parametre na kraju (podrazumevano `null`, pa postojeći pozivi rade bez izmene): `NetworkException` (`requestSent`, vidi gore),
  `FiskalizacijaResult` (`success`, `status`, `brojRacuna`, `klijentskiBroj`, `duplikat`,
  `ukupanIznos`, `pfrDatumVreme`, `qrUrl`, `esirOznaka`, `errorCode`,
  `existingCopyInvoiceId`), `WebhookPayload` (`klijentskiBroj`, `brojRacuna`, `success`,
  `datumIzdavanja`, `errorMessage`, `timestamp`) i `TenantStatus` (`unlimitedInvoices`,
  podrazumevano `false`). Nove metode su
  `FiskalizacijaResult::isSuccess()`, `Invoice::setAdvertisementText()`,
  `Invoice::setOriginalInvoiceNumber()`, `FiskalizacijaResult::__unserialize()`,
  `WebhookPayload::__unserialize()` i `TenantStatus::__unserialize()` (vidi
  „Nadogradnja na 1.2.0“).

### Promene u 1.1.1

- `CurlHttpClient` više ne poziva `curl_close()` (od PHP 8.0 nema efekta), pa na PHP 8.5
  zahtev ne daje E_DEPRECATED „Function curl_close() is deprecated“.
- `Config` proverava konfiguraciju ponavljanja i baca `InvalidArgumentException` van
  opsega: `maxRetries` mora biti 0–10 (`Config::MAX_RETRIES`), `retryBaseDelayMs` ≥ 0,
  `retryMultiplier` ≥ 1. Podrazumevane vrednosti (3, 1000, 2) su u opsegu.
- Pauza između pokušaja je najviše 60 s (`Config::MAX_RETRY_DELAY_MS` = 60000 ms) i
  računa se bez prelivanja celog broja (ranije su vrlo velike vrednosti davale
  `TypeError`). Podrazumevane pauze su iste: 1 s, 2 s, 4 s.
- `Config` napravljen kroz `unserialize()` (konstruktor se ne poziva, npr. objekat
  sačuvan sa 1.1.0) sa `maxRetries` > 10 radi najviše 10 ponovljenih pokušaja. Sa
  negativnim `maxRetries` poziv baca `InvalidArgumentException` pre slanja (nijedan
  zahtev ne odlazi na server). Za takav objekat sa `retryBaseDelayMs` < 0 pauza je 0,
  a sa `retryMultiplier` < 1 svaka pauza je jednaka bazi (najviše 60 s).
- API ključ i tajna u `Config::__construct`, `Config::sandbox`, `Config::production`,
  `EfiskalizacijaClient::create`, `EfiskalizacijaClient::sandbox` i
  `HmacSigner::__construct` nose `#[\SensitiveParameter]`. To važi samo za
  trag izuzetka iz `Config`-a i fabrika: na PHP ≥ 8.2 taj trag (`getTrace()`,
  `(string) $e`) ne sadrži njihove vrednosti. Na PHP 8.1 atribut nema efekta — tamo
  uključite `zend.exception_ignore_args=On` ili ne prosleđujte trag izuzetka iz
  `Config`-a sistemu za praćenje grešaka. Atribut NE štiti izuzetke iz slanja zahteva:
  `NetworkException::getTrace()` nosi zaglavlja zahteva (`X-API-Key`, `X-Signature`)
  kao argument metode `request()` HTTP klijenta na svakoj verziji PHP-a, osim uz
  `zend.exception_ignore_args=On`. Posledica na PHP ≥ 8.2, samo uz
  `zend.exception_ignore_args=0` (inače trag nema argumente): `serialize()` izuzetka iz
  `Config`-a ili fabrika baca
  „Serialization of 'SensitiveParameterValue' is not allowed“.
- Potpis zahteva (v2) i potpisi javnih metoda su bez promene.

### Promene u 1.1.0

- Svaki zahtev SDK klijenta potpisuje se v2 (cilj sa query stringom) i nosi
  `X-Signature-Version: 2`.
- Nove metode `HmacSigner::signV2()` i `HmacSigner::getAuthHeadersV2()`. Postojeće
  `sign()` i `getAuthHeaders()` su nepromenjene (v1, kao u 1.0.2).
- Query string se kodira po RFC 3986: razmak je `%20` (ranije `+`), `~` ostaje nekodiran
  (ranije `%7E`), separator je uvek `&` (nezavisno od `arg_separator.output`), a query
  bez vrednosti (npr. samo `null`) ne dodaje `?`.
- Ponovni pokušaj dobija strogo veći `X-Timestamp` od prethodnog pokušaja istog poziva
  (vidi „Retry logika“), bez dodatnog čekanja.
- `AbstractApi::__construct()` ima dva nova opciona parametra, `$clock` i `$sleep`
  (zamena sata i pauze, npr. u testovima); postojeći pozivi se ne menjaju.

### Nadogradnja na 1.2.0

Za fiskalizaciju **sa** brojem računa (`setInvoiceNumber()`) i za GET metode ponavljanje
zahteva je isto kao u 1.1.1; rezultat i webhook sada nose polja koja su ranije bila `null`
(vidi „Promene u 1.2.0“). Izmene mogu trebati u ovim slučajevima:

- **`fiskalizuj()` bez broja računa, `email()->send()`, `test()->run()`**: posle prekida
  veze, 502, 503 ili 504 dobijate izuzetak posle prvog pokušaja. Pre novog slanja proverite da
  li je zahtev obrađen; za fiskalizaciju je najbolje da uvek šaljete broj računa.
- **Sopstveni `HttpClientInterface`** koji baca `NetworkException`: bez `requestSent`
  SDK tretira ishod kao nepoznat i takav POST ne ponavlja. Prosledite
  `requestSent: false` samo kad je sigurno da nijedan bajt zahteva nije poslat.
- **Kod koji koristi `setIdempotencyKey()`** radi i dalje (zastarelo upozorenje samo u
  alatima za statičku analizu); dodajte `setInvoiceNumber()`.
- **Kod koji čita `$result->qrCode` ili `$result->poruka`**: do 1.1.1 su uvek bili `null`,
  sada nose vrednost (`qrCode` je QR kod kao base64 PNG, ne adresa — adresa za proveru je
  `qrUrl`). Grana koja je očekivala `null` sada dobija vrednost.
- **Kod koji proverava ishod preko `pfrBroj`**: koristite `isSuccess()`; za neuspeh je
  `pfrBroj` i dalje prazan string.
- **Webhook handler**: `pfrBroj`, `racunId` i `iznos` su do 1.1.1 za telo servera bili
  `null` (polja su u `data`); sada nose vrednost. Porudžbinu povežite po `klijentskiBroj`.
  Za račune izdate sa 1.2.0 `rawData` nosi i `data.kupac.pib` / `data.kupac.jmbg` i
  `data.stavke[].sifra_artikla`; handler koji je očekivao da ih nema dobija vrednost.
- **`setNote()`**: tekst se ne čuva uz račun (ne vidi se na računu, u listi računa ni u
  admin panelu). Za tekst na računu koristite
  `setAdvertisementText()` — štampa se na računu, pa ne šaljite interne beleške.
- **Šifra artikla (`InvoiceItem` sa `sifra`)**: šifra se sada čuva uz stavku. Šifra
  duža od 50 znakova se ne šalje i ne čuva, kao do 1.1.1 (račun se izdaje); ako je šifra
  potrebna uz račun, skratite je pre slanja.
- **Kod ili test koji čita `Customer::toArray()` / `InvoiceItem::toArray()`**: kupac nosi
  `pib`, `jmbg` ili `jbkjs` umesto `tip` i `identifikator` (`Customer::javniSektor()` bez
  `$pib` i dalje nosi `tip` i `identifikator`), a stavka `sifra_artikla` umesto `sifra`.
  Javni konstruktori i fabrike (`Customer::pravnoLice()` …) su isti.
- **`Customer::javniSektor()`**: postojeći pozivi (bez `$pib`) šalju isto telo kao u 1.1.1,
  pa server račun izdaje ili odbija kao do sada. Za oznaku kupca `12:PIB:JBKJS` dodajte PIB
  kupca kao nov parametar na kraju: `Customer::javniSektor('54321', pib: '123456789')`; do
  1.1.1 server je račun sa JBKJS-om odbijao. Avans za javni sektor izdajte kroz
  `Customer::pravnoLice()`.
- **Kopija računa** (`InvoiceType::Kopija`): bez promene dok ne pozovete
  `setOriginalInvoiceNumber()` (vidi „Kopija računa“).
- **`TenantStatus::$maxInvoicesPerMonth`** je 0 i za nalog bez limita; proverite prvo
  `unlimitedInvoices`.
- **`FiskalizacijaResult`, `WebhookPayload`, `TenantStatus` ili `Invoice` sačuvan kroz
  `serialize()`** (keš, red poruka, sesija) sa 1.1.1 ili starijom verzijom: `unserialize()`
  sa 1.2.0 radi, sačuvana svojstva zadržavaju vrednost, a nova (`success`, `duplikat`,
  `klijentskiBroj`…) su `null`; `unlimitedInvoices` je `false`, a `Invoice` se šalje kao
  ranije (bez broja originala). Kupac iz `Customer::javniSektor()` sačuvan sa 1.1.1 šalje se
  kao u 1.1.1 (`tip` + `identifikator`), osim vrednosti u obliku `PIB:JBKJS`, koja ide kao
  `jbkjs`. `isSuccess()` za takav rezultat odlučuje po `pfrBroj`.

Povratak na 1.1.x (`composer require infogrambeg/efiskalizacija-php-sdk:1.1.1`) radi:
objekat koji je serijalizovala 1.2.0, a deserijalizuje ga 1.1.x, zadržava vrednosti
svojstava koja postoje u 1.1.x. Na PHP ≥ 8.2 svako svojstvo dodato u 1.2.0 pri tome daje
E_DEPRECATED „Creation of dynamic property“ (11 kod `FiskalizacijaResult`, 6 kod
`WebhookPayload`, 1 kod `TenantStatus` i 2 kod `Invoice`); vrednosti se čitaju normalno.
`Invoice` sa brojem originala 1.1.x šalje kao kopiju bez broja originala
(`tip_racuna: "kopija"`), a 1.1.x šifru artikla i kupca šalje pod starim ključevima.

### Nadogradnja sa 1.0.x

Za korisnike koji samo pozivaju metode klijenta (`$client->fiskalizacija()`,
`->invoices()`, `->pdf()` …) nadogradnja ne traži izmene koda. Izmene mogu trebati u
ovim slučajevima:

- **Direktan poziv `HmacSigner::sign()` / `getAuthHeaders()`** (sopstveni potpis bez SDK
  klijenta) i dalje radi bez izmena: obe metode potpisuju v1 bajt-isto kao 1.0.2 (query
  se odseca, bez `X-Signature-Version`). Za v2 pređite na `signV2()` /
  `getAuthHeadersV2()`: `$target` je tačan cilj **sa query-jem** kako se šalje, a zahtev
  mora nositi zaglavlje `X-Signature-Version: 2` — `getAuthHeadersV2()` ga vraća,
  `signV2()` ne.
- **Sopstveni `HttpClientInterface`**: mora poslati `$url` bez prepakivanja (vidi gore).
- **Bazni URL sa putanjom**: vidi gore.
- **`Config` sa granicama ponavljanja van opsega**: `Config` sa
  `maxRetries` van 0–10, `retryBaseDelayMs` < 0 ili `retryMultiplier` < 1
  baca `InvalidArgumentException` pri konstrukciji od 1.1.1 (ranije je prihvatan). Od 1.1.1 je i pauza između pokušaja najviše 60 s
  (`Config::MAX_RETRY_DELAY_MS`), pa konfiguracija sa dužim pauzama čeka kraće.

Povratak na `1.0.2` (`composer require infogrambeg/efiskalizacija-php-sdk:1.0.2`) radi,
jer server i dalje prima potpis v1. Objekat koji je serijalizovala 1.1.0, a deserijalizuje
ga 1.0.2 na PHP ≥ 8.2, daje E_DEPRECATED za dinamička svojstva `$clock` i `$sleep`
(„Creation of dynamic property“); zahtevi rade.

## Retry logika

SDK automatski ponavlja zahtev posle prolazne greške (exponential backoff), ali od 1.2.0
samo zahtev bezbedan za ponavljanje ili zahtev za koji je sigurno da ga server nije
obradio. Zahtev je **bezbedan za
ponavljanje** kad je GET ili `POST /fiskalizacija` čije telo nosi `broj_racuna` koji
posle `trim` nije prazan (vidi „Idempotency“). Svi ostali zahtevi — `fiskalizuj()` bez
broja računa, `email()->send()`, `test()->run()`, POST iz sopstvene podklase na drugu
rutu ili bez broja računa — ponavljaju se samo kad je sigurno da server zahtev nije
obradio:

| Greška                              | GET i `POST /fiskalizacija` sa brojem | Ostali zahtevi |
| ----------------------------------- | ------------------------------------- | -------------- |
| 429 (Rate Limit)                    | Da                                    | Da             |
| 502 (Bad Gateway)                   | Da                                    | Ne             |
| 503 (Unavailable)                   | Da                                    | Ne             |
| 504 (Gateway Timeout)               | Da                                    | Ne             |
| Mrežna greška, zahtev nije poslat   | Da                                    | Da             |
| Mrežna greška, ishod nepoznat       | Da                                    | Ne             |
| 400 (Validacija)                    | Ne                                    | Ne             |
| 401 (Auth)                          | Ne                                    | Ne             |
| 403 (Zabranjeno)                    | Ne                                    | Ne             |
| 422 (Validacija)                    | Ne                                    | Ne             |

429 server vraća pre obrade zahteva (ograničenje učestalosti). Za 503 to ne važi: Apache
(`mod_proxy_fcgi`) ga vraća, kao HTML bez `Retry-After`, i kad je PHP zahtev već obradio,
a odgovor se izgubio (prekinut radnik, istek roka obrade). „Zahtev nije poslat“ je
`NetworkException` sa `wasRequestSent() === false` (`CurlHttpClient`: cURL nije poslao
nijedan bajt — zatvoren port, nerazrešiv host, neuspela TLS veza). „Ishod nepoznat“ je
svaka druga mrežna greška (npr. istek roka posle slanja). Kad SDK zahtev ne ponovi zato
što ishod nije poznat, izuzetak se baca odmah, bez pauze, uz WARNING u logger-u (bez
tela, ključa i potpisa).

Ni bezbedan zahtev nije potpuno zaštićen: kad se obrada na serveru prekine ili ne uspe
posle fiskalizacije (pad servera, istek odgovora Poreske uprave), račun postoji, ali ga
server nije upisao, pa se ne vidi ni u `invoices()->list()` ni u admin panelu, a ponovljen
zahtev sa istim brojem izdaje drugi račun (vidi „Idempotency“). Kad ishod ostane nepoznat i
posle poslednjeg pokušaja, sa brojem računa ili bez njega, proverite da li je račun izdat;
provera nalazi samo upisan račun, pa kad ga ne nađe, pre novog slanja obratite se podršci.

Podrazumevano: početni zahtev + do 3 ponavljanja (`maxRetries: 3`), pauze 1 s / 2 s / 4 s.
Konfigurisano u `Config`.

Svaki ponovni pokušaj se potpisuje iznova i dobija **strogo veći** `X-Timestamp` od
prethodnog pokušaja, pa nijedna dva pokušaja nemaju isti potpis: timestamp pokušaja je
max(tekući sat, prethodni + 1), bez ikakvog dodatnog čekanja. Prvi pokušaj nosi tekući
sat. Kad su pokušaji brži od 1 s (razmak ispod 1 s), timestamp može biti ispred sata
najviše za k sekundi, gde je k broj ponovljenih pokušaja do tog trenutka (najviše
`maxRetries`); kad sat tokom poziva skoči unazad za J sekundi, za J + k. Server prihvata
timestamp ±300 s od svog sata, pa sat servera i klijenta treba da budu sinhronizovani
(NTP).

Granica: od 1.1.1 `maxRetries` je najviše 10 (`Config::MAX_RETRIES`). Za `Config`
dobijen kroz `unserialize()` (bez konstruktora) `maxRetries` > 10 se ograničava na 10, a
negativan baca `InvalidArgumentException` pre slanja. Zato je timestamp ispred sata
najviše 10 s (uz skok sata unazad J + 10), daleko unutar tolerancije servera.
Pauza između pokušaja je najviše 60 s (`Config::MAX_RETRY_DELAY_MS`).

## Webhook

```php
use Efiskalizacija\Webhook\WebhookPayload;

// U vašem webhook handler-u
$payload = WebhookPayload::fromJson(file_get_contents('php://input'));

if ($payload->isFiscalized()) {
    // Račun uspešno fiskalizovan; porudžbinu povežite po broju iz setInvoiceNumber()
    $porudzbina = $payload->klijentskiBroj; // null kad račun nije poslat sa brojem
    $pfr = $payload->pfrBroj;
    $iznos = $payload->iznos;
}

if ($payload->isFailed()) {
    // Fiskalizacija neuspešna
    $razlog = $payload->errorMessage;
}
```

Server šalje polja računa u `data` (`invoice_id`, `invoice_number`, `klijentski_broj`,
`success`, `pfr_broj`, `ukupan_iznos`, `datum_izdavanja`, `error_message`); SDK ih čita u
`racunId`, `brojRacuna`, `klijentskiBroj`, `success`, `pfrBroj`, `iznos`, `datumIzdavanja`
i `errorMessage`. Detalji računa (`stavke`, `kupac`, `nacin_placanja` …) su u `rawData`.

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

### Okruženja

| Okruženje | URL | Namena |
| --------- | --- | ------ |
| Production | `https://efiskalizacija.cloud` | Pravi fiskalni računi |
| Sandbox | `https://staging.efiskalizacija.cloud` | Testiranje integracije |

```php
// Sandbox (testiranje)
$client = EfiskalizacijaClient::sandbox($apiKey, $apiSecret);

// Production (podrazumevano)
$client = EfiskalizacijaClient::create($apiKey, $apiSecret);
```

### Preporučena konfiguracija (.env)

Nikad ne čuvajte API kredencijale u kodu. Koristite environment varijable:

```env
EFISK_API_KEY=efisk_1_abc123...
EFISK_API_SECRET=your-64-char-secret
EFISK_SANDBOX=true
```

```php
$apiKey = $_ENV['EFISK_API_KEY'] ?? getenv('EFISK_API_KEY');
$apiSecret = $_ENV['EFISK_API_SECRET'] ?? getenv('EFISK_API_SECRET');
$sandbox = filter_var($_ENV['EFISK_SANDBOX'] ?? getenv('EFISK_SANDBOX'), FILTER_VALIDATE_BOOLEAN);

$client = $sandbox
    ? EfiskalizacijaClient::sandbox($apiKey, $apiSecret)
    : EfiskalizacijaClient::create($apiKey, $apiSecret);
```

### Napredna konfiguracija

```php
use Efiskalizacija\Config;

$config = new Config(
    apiKey: $apiKey,
    apiSecret: $apiSecret,
    baseUrl: 'https://efiskalizacija.cloud',  // default
    timeout: 30,              // HTTP timeout (sekunde)
    connectTimeout: 10,       // Connection timeout (sekunde)
    maxRetries: 3,            // Broj retry pokušaja (0–10)
    retryBaseDelayMs: 1000,   // Početni delay (ms, ≥ 0; pauza najviše 60 s)
    retryMultiplier: 2,       // Multiplier za exponential backoff (≥ 1)
);

$client = new EfiskalizacijaClient($config);
```

## Testiranje

```bash
composer test
```

## Licenca

MIT
