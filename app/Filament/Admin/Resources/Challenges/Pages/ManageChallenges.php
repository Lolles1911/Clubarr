<?php

namespace App\Filament\Admin\Resources\Challenges\Pages;

use App\Filament\Admin\Resources\Challenges\ChallengeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageChallenges extends ManageRecords
{
    protected static string $resource = ChallengeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('New challenge'))];
    }
}
