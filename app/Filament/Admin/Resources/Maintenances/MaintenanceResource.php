<?php

namespace App\Filament\Admin\Resources\Maintenances;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Maintenances\Pages\ManageMaintenances;
use App\Models\Maintenance;
use App\Models\MediaServer;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Planned maintenance: members are told (mail, Discord, a banner), and "down" alerts wait while it lasts. */
class MaintenanceResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = Maintenance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 30;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Servers;

    public static function getModelLabel(): string
    {
        return __('maintenance');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Maintenance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('media_server_id')->label(__('Server'))->placeholder(__('All servers'))
                ->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->all())->columnSpanFull(),
            DateTimePicker::make('starts_at')->label(__('From'))->seconds(false)->required()->default(fn () => now()->addDay()->setTime(2, 0)),
            DateTimePicker::make('ends_at')->label(__('To'))->seconds(false)->required()->after('starts_at')->default(fn () => now()->addDay()->setTime(4, 0)),
            Textarea::make('note')->label(__('What happens (optional)'))->rows(2)->maxLength(500)->columnSpanFull()
                ->placeholder(__('e.g. "New disks; the server is off for a few hours."')),
            Toggle::make('tell')->label(__('Tell the members now (mail, Discord and a banner on their page)'))->default(true)->columnSpanFull()
                ->visibleOn('create'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('mediaServer.name')->label(__('Server'))->placeholder(__('All servers')),
                TextColumn::make('starts_at')->label(__('From'))->dateTime('j M Y, H:i'),
                TextColumn::make('ends_at')->label(__('To'))->dateTime('j M Y, H:i'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (Maintenance $m) => $m->isNow() ? __('Now') : ($m->ends_at->isPast() ? __('Done') : __('Planned')))
                    ->color(fn (Maintenance $m) => $m->isNow() ? 'warning' : ($m->ends_at->isPast() ? 'gray' : 'info')),
                TextColumn::make('note')->label(__('Note'))->limit(60)->placeholder('—')->visibleFrom('md'),
            ])
            ->recordActions([
                DeleteAction::make()->after(fn (Maintenance $record) => Audit::log('maintenance.deleted', null, ['when' => $record->describe()])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMaintenances::route('/')];
    }
}
