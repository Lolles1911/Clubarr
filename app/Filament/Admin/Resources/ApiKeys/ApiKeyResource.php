<?php

namespace App\Filament\Admin\Resources\ApiKeys;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\ApiKeys\Pages\ManageApiKeys;
use App\Models\ApiKey;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Keys for the REST API (docs/api.md). A key is shown once; deleting it stops it at once. */
class ApiKeyResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = ApiKey::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 30;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::System;

    public static function getModelLabel(): string
    {
        return __('API key');
    }

    public static function getPluralModelLabel(): string
    {
        return __('API keys');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('Name'))->required()->maxLength(60)->placeholder(__('e.g. Home Assistant'))->columnSpanFull(),
            Toggle::make('can_invite')->label(__('May make invitations (from a template)'))->columnSpanFull()
                ->helperText(__('Off: the key can only read: status, members and invitations.')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->emptyStateDescription(__('With a key, other programs can read Clubarr\'s status, members and invitations at /api/v1. See docs/api.md.'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable(),
                TextColumn::make('hint')->label(__('Key'))->formatStateUsing(fn (string $state) => $state.'…')->fontFamily('mono'),
                IconColumn::make('can_invite')->label(__('Invitations'))->boolean(),
                TextColumn::make('last_used_at')->label(__('Last used'))->since()->placeholder(__('Never')),
                TextColumn::make('creator.name')->label(__('Made by'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('Made'))->date()->visibleFrom('md'),
            ])
            ->recordActions([
                DeleteAction::make()->label(__('Revoke'))->modalHeading(__('Revoke this key?'))
                    ->modalDescription(__('Programs using it stop working at once.'))
                    ->after(fn (ApiKey $record) => Audit::log('api.key_revoked', null, ['name' => $record->name])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageApiKeys::route('/')];
    }
}
