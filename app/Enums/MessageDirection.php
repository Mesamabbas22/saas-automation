<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum MessageDirection: string implements HasColor, HasLabel
{
    case In = 'in';
    case Out = 'out';

    public function getLabel(): string
    {
        return $this === self::In ? 'Incoming' : 'Outgoing';
    }

    public function getColor(): string
    {
        return $this === self::In ? 'info' : 'success';
    }
}
