<?php

declare(strict_types=1);

namespace Efiskalizacija\Enum;

enum InvoiceType: string
{
    case Prodaja = 'prodaja';
    case Proforma = 'proforma';
    case Kopija = 'kopija';
    case Obuka = 'obuka';
    case Avans = 'avans';
}
