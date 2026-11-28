<?php

namespace App\Filament\Admin\Resources\MovieNights\Pages;

use App\Filament\Admin\Resources\MovieNights\MovieNightResource;
use App\Models\MovieNight;
use App\Services\Audit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMovieNights extends ManageRecords
{
    protected static string $resource = MovieNightResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Plan a movie night'))
            ->after(fn (MovieNight $record) => Audit::log('movie_night.planned', $record, ['title' => $record->title]))];
    }
}
