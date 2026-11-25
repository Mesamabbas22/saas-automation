<?php

namespace App\Filament\Admin\Resources\WhatsAppAccounts\Pages;

use App\Filament\Admin\Resources\WhatsAppAccounts\WhatsAppAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWhatsAppAccount extends EditRecord
{
    protected static string $resource = WhatsAppAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
