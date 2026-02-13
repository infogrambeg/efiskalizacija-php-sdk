<?php

declare(strict_types=1);

namespace Efiskalizacija\Enum;

enum TransactionType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
}
