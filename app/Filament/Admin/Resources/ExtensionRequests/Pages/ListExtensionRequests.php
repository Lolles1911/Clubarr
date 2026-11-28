<?php

namespace App\Filament\Admin\Resources\ExtensionRequests\Pages;

use App\Filament\Admin\Resources\ExtensionRequests\ExtensionRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListExtensionRequests extends ListRecords
{
    protected static string $resource = ExtensionRequestResource::class;
}
