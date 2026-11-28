<?php

namespace App\Filament\Admin\Resources\AuditLogs;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\AuditLogs\Pages\ListAuditLogs;
use App\Filament\Admin\Resources\AuditLogs\Tables\AuditLogsTable;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** The audit log: read only. */
class AuditLogResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 90;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Insights;

    public static function getModelLabel(): string
    {
        return __('log entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Log');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AuditLogsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditLogs::route('/')];
    }
}
