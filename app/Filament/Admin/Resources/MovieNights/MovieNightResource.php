<?php

namespace App\Filament\Admin\Resources\MovieNights;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\MovieNights\Pages\ManageMovieNights;
use App\Models\MediaServer;
use App\Models\MovieNight;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Movie nights: a card on members' page, "I'm coming", and a reminder an hour or two before. */
class MovieNightResource extends Resource
{
    protected static ?string $model = MovieNight::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFilm;

    protected static ?int $navigationSort = 70;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('movie night');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Movie nights');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label(__('What'))->required()->maxLength(120)->placeholder(__('e.g. Dune: Part Two'))->columnSpanFull(),
            DateTimePicker::make('starts_at')->label(__('When'))->seconds(false)->required()->after('now'),
            Select::make('media_server_id')->label(__('For'))->placeholder(__('Everyone'))->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->all()),
            Textarea::make('note')->label(__('A few words'))->rows(2)->maxLength(1000)->columnSpanFull()
                ->placeholder(__('e.g. "Start it at 20:00 and join us on Discord."')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('What'))->weight('medium'),
                TextColumn::make('starts_at')->label(__('When'))->dateTime('j M Y, H:i'),
                TextColumn::make('mediaServer.name')->label(__('For'))->placeholder(__('Everyone')),
                TextColumn::make('attendees_count')->counts('attendees')->label(__('Coming')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->after(fn (MovieNight $record) => Audit::log('movie_night.deleted', null, ['title' => $record->title])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMovieNights::route('/')];
    }
}
