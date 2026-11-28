<?php

namespace App\Filament\Admin\Resources\MediaServers;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\MediaServers\Pages\CreateMediaServer;
use App\Filament\Admin\Resources\MediaServers\Pages\EditMediaServer;
use App\Filament\Admin\Resources\MediaServers\Pages\ListMediaServers;
use App\Filament\Admin\Resources\MediaServers\Schemas\MediaServerForm;
use App\Filament\Admin\Resources\MediaServers\Tables\MediaServersTable;
use App\Models\MediaServer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class MediaServerResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = MediaServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Servers;

    public static function getModelLabel(): string
    {
        return __('media server');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Media servers');
    }

    public static function form(Schema $schema): Schema
    {
        return MediaServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MediaServersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMediaServers::route('/'),
            'create' => CreateMediaServer::route('/create'),
            'edit' => EditMediaServer::route('/{record}/edit'),
        ];
    }
}
