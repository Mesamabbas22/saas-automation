<?php

namespace App\Filament\App\Resources\MessageLogs\Pages;

use App\Filament\App\Resources\MessageLogs\MessageLogResource;
use Filament\Resources\Pages\ListRecords;

class ListMessageLogs extends ListRecords
{
    protected static string $resource = MessageLogResource::class;
}
