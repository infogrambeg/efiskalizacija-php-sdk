<?php

declare(strict_types=1);

namespace Efiskalizacija\DTO;

use Efiskalizacija\Enum\PaymentType;

final class Payment
{
    public function __construct(
        public readonly PaymentType $tip,
        public readonly float $iznos,
    ) {
        if ($this->iznos <= 0) {
            throw new \InvalidArgumentException('Iznos placanja mora biti pozitivan');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tip' => $this->tip->value,
            'iznos' => $this->iznos,
        ];
    }
}
