<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Pages\ViewUser;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use App\Support\AdminRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    protected static ?string $recordTitleAttribute = 'name';

    /** Moderators see and change members only, never admins. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(! AdminRole::full(), fn (Builder $q) => $q->where('is_admin', false));
    }

    public static function canCreate(): bool
    {
        return AdminRole::full();
    }

    public static function getModelLabel(): string
    {
        return __('member');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Members');
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
