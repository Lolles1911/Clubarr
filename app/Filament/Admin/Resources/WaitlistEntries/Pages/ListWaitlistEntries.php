<?php

namespace App\Filament\Admin\Resources\WaitlistEntries\Pages;

use App\Filament\Admin\Resources\WaitlistEntries\WaitlistEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListWaitlistEntries extends ListRecords
{
    protected static string $resource = WaitlistEntryResource::class;
}
