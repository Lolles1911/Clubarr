<?php

namespace App\Filament\Admin\Resources\AccessRequests\Pages;

use App\Filament\Admin\Resources\AccessRequests\AccessRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessRequests extends ListRecords
{
    protected static string $resource = AccessRequestResource::class;
}
