<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Cod = 'cod';
    case JazzCash = 'jazzcash';
    case Easypaisa = 'easypaisa';

    public function getLabel(): string
    {
        return match ($this) {
            self::Cod => 'Cash on Delivery',
            self::JazzCash => 'JazzCash',
            self::Easypaisa => 'Easypaisa',
        };
    }
}
