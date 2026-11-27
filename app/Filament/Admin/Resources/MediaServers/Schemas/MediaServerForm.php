<?php

namespace App\Filament\Admin\Resources\MediaServers\Schemas;

use App\Enums\ServerType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MediaServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('type')->label(__('Type'))->options(ServerType::options())->required()->live()
                    ->disabledOn('edit'),
                TextInput::make('name')->label(__('Name'))->required()->maxLength(60)
                    ->helperText(__('What members see, e.g. "Movies & TV".')),
                TextInput::make('url')->label(__('Address Clubarr uses'))->required()->url()->maxLength(255)
                    ->placeholder('http://192.168.1.10:8096')
                    ->helperText(__('How Clubarr reaches the server, often inside your network.')),
                TextInput::make('public_url')->label(__('Address members open'))->url()->maxLength(255)
                    ->placeholder('https://watch.example.com')
                    ->helperText(fn (Get $get) => $get('type') === ServerType::Plex->value ? __('Not needed for Plex: members use app.plex.tv.') : __('Leave empty to use the address above.')),
                TextInput::make('api_key')->label(fn (Get $get) => ServerType::tryFrom((string) $get('type'))?->keyLabel() ?? __('API key'))
                    ->password()->revealable()->maxLength(500)->autocomplete('off')
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation, Get $get) => ($operation === 'edit' ? __('Stored encrypted. Leave empty to keep the current key.').' ' : '').match ($get('type')) {
                        ServerType::Plex->value => __('The server owner\'s Plex token (see Plex\'s "Finding an authentication token" guide).'),
                        ServerType::Silo->value => __('A Silo API key of an admin account, with full access.'),
                        default => __('Dashboard → API Keys on the server.'),
                    }),
                Toggle::make('is_enabled')->label(__('In use'))->default(true)
                    ->helperText(__('Off: new invitations can\'t give access to it. Existing users are not changed.')),
            ]),
        ]);
    }
}
