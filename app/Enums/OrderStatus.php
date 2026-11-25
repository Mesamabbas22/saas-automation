<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Dispatched = 'dispatched';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Confirmed => 'info',
            self::Dispatched => 'primary',
            self::Delivered => 'success',
            self::Cancelled => 'danger',
        };
    }

    /** Customer-facing status message (Roman Urdu + English). */
    public function customerMessage(): string
    {
        return match ($this) {
            self::Pending => 'Aap ka order pending hai. / Your order is pending.',
            self::Confirmed => 'Aap ka order confirm ho gaya hai. / Your order has been confirmed.',
            self::Dispatched => 'Aap ka order rawana kar diya gaya hai. / Your order has been dispatched.',
            self::Delivered => 'Aap ka order deliver ho gaya hai. Shukriya! / Your order has been delivered. Thank you!',
            self::Cancelled => 'Aap ka order cancel kar diya gaya hai. / Your order has been cancelled.',
        };
    }
}
