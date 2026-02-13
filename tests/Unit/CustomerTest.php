<?php

declare(strict_types=1);

namespace Efiskalizacija\Tests\Unit;

use Efiskalizacija\DTO\Customer;
use PHPUnit\Framework\TestCase;

final class CustomerTest extends TestCase
{
    public function testPravnoLice(): void
    {
        $customer = Customer::pravnoLice('123456789', 'Firma DOO', 'Knez Mihailova 10', 'Beograd');

        $data = $customer->toArray();

        $this->assertSame('pravno_lice', $data['tip']);
        $this->assertSame('123456789', $data['identifikator']);
        $this->assertSame('Firma DOO', $data['ime']);
        $this->assertSame('Knez Mihailova 10', $data['adresa']);
        $this->assertSame('Beograd', $data['mesto']);
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

        $this->assertSame('fizicko_lice', $data['tip']);
        $this->assertSame('1234567890123', $data['identifikator']);
        $this->assertSame('Petar Petrovic', $data['ime']);
    }

    public function testFizickoLiceInvalidJmbg(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('13 cifara');

        Customer::fizickoLice('123');
    }

    public function testJavniSektor(): void
    {
        $customer = Customer::javniSektor('12345', 'Ministarstvo');

        $data = $customer->toArray();

        $this->assertSame('javni_sektor', $data['tip']);
        $this->assertSame('12345', $data['identifikator']);
    }

    public function testAnonimni(): void
    {
        $customer = Customer::anonimni('test@example.com');

        $data = $customer->toArray();

        $this->assertArrayNotHasKey('tip', $data);
        $this->assertArrayNotHasKey('identifikator', $data);
        $this->assertSame('test@example.com', $data['email']);
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
