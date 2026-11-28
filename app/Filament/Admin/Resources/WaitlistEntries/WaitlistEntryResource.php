<?php

namespace App\Filament\Admin\Resources\WaitlistEntries;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\WaitlistEntries\Pages\ListWaitlistEntries;
use App\Filament\Admin\Resources\WaitlistEntries\Tables\WaitlistEntriesTable;
use App\Models\WaitlistEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WaitlistEntryResource extends Resource
{
    protected static ?string $model = WaitlistEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 50;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('waiting list entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Waiting list');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return WaitlistEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListWaitlistEntries::route('/')];
    }
}
