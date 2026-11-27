<?php

namespace App\Filament\Admin\Resources\MediaServers\Pages;

use App\Filament\Admin\Resources\MediaServers\MediaServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMediaServers extends ListRecords
{
    protected static string $resource = MediaServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
