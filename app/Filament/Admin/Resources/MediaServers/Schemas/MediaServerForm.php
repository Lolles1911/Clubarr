<?php

namespace App\Filament\Admin\Resources\MediaServers\Schemas;

use App\Enums\ServerType;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Services\PlexLogin;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Js;
use Throwable;

class MediaServerForm
{
    /**
     * The Plex servers of the account that approved the PIN (kept in the
     * session, never in the form until a server is chosen).
     *
     * @return list<array{name: string, uri: string, local: bool}>
     */
    private static function plexServers(): array
    {
        $login = app(PlexLogin::class);
        $token = (string) session('admin.plex.token', '');
        if ($token === '' && is_array($pin = session('admin.plex.pin'))) {
            try {
                $token = (string) $login->token((string) $pin['id'], (string) $pin['code']);
            } catch (Throwable) {
                $token = '';
            }
            if ($token !== '') {
                session()->forget('admin.plex.pin');
                session()->put('admin.plex.token', $token);
            }
        }
        if ($token === '') {
            return [];
        }
        try {
            return $login->servers($token);
        } catch (Throwable) {
            return [];
        }
    }

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
                TextInput::make('capacity')->label(__('Members at most'))->numeric()->minValue(1)->maxValue(100000)
                    ->helperText(__('Empty: no limit. When full, invitations wait, and the waiting list (Settings) takes over.')),
                Toggle::make('is_enabled')->label(__('In use'))->default(true)
                    ->helperText(__('Off: new invitations can\'t give access to it. Existing users are not changed.')),
                Actions::make([
                    // Plex: sign in as the server owner and pick the server; fills the address and token.
                    Action::make('plexSignIn')->label(__('Sign in with Plex'))->color('warning')->icon('heroicon-m-arrow-right-end-on-rectangle')
                        ->visible(fn (Get $get) => $get('type') === ServerType::Plex->value)
                        ->extraAttributes(['x-on:click' => "window.clubarrPlex = window.open('about:blank', 'clubarr-plex', 'width=600,height=720')"])
                        ->action(function ($livewire) {
                            try {
                                $pin = app(PlexLogin::class)->start();
                            } catch (Throwable) {
                                $livewire->js('window.clubarrPlex && window.clubarrPlex.close()');
                                Notification::make()->title(__('plex.tv could not be reached. Please try again.'))->danger()->send();

                                return;
                            }
                            session()->put('admin.plex.pin', ['id' => $pin['id'], 'code' => $pin['code']]);
                            $livewire->js('if (window.clubarrPlex) { window.clubarrPlex.location = '.Js::from($pin['url']).' } else { window.open('.Js::from($pin['url']).') }');
                            Notification::make()->title(__('Approve Clubarr in the Plex window, then press "Choose server".'))->info()->persistent()->send();
                        }),
                    Action::make('plexPick')->label(__('Choose server'))->color('gray')
                        ->visible(fn (Get $get) => $get('type') === ServerType::Plex->value)
                        ->schema([
                            Select::make('server')->label(__('Your Plex servers'))->required()
                                ->options(fn () => collect(self::plexServers())->mapWithKeys(fn ($s) => [$s['uri'] => $s['name'].' · '.$s['uri'].($s['local'] ? ' ('.__('local').')' : '')])->all()),
                        ])
                        ->action(function (array $data, Set $set) {
                            $token = (string) session('admin.plex.token', '');
                            $server = collect(self::plexServers())->firstWhere('uri', (string) $data['server']);
                            if ($token === '' || $server === null) {
                                Notification::make()->title(__('Sign in with Plex first.'))->warning()->send();

                                return;
                            }
                            $set('url', $server['uri']);
                            $set('name', $server['name']);
                            $set('api_key', $token);
                            session()->forget('admin.plex.token');
                            Notification::make()->title(__('Filled in. Press Test, then Create.'))->success()->send();
                        }),
                    Action::make('test')->label(__('Test'))->icon('heroicon-m-signal')->color('gray')
                        ->action(function (Get $get, ?MediaServer $record) {
                            $type = ServerType::tryFrom((string) $get('type'));
                            $key = filled($get('api_key')) ? (string) $get('api_key') : (string) $record?->api_key;
                            if ($type === null || blank($get('url')) || $key === '') {
                                Notification::make()->title(__('Fill in the type, address and key first.'))->warning()->send();

                                return;
                            }
                            $server = new MediaServer(['type' => $type, 'name' => (string) ($get('name') ?: $type->label()), 'url' => (string) $get('url'), 'api_key' => $key,
                                'settings' => $record?->settings]);
                            try {
                                $info = $server->driver()->test();
                                $libraries = count($server->driver()->libraries());
                                Notification::make()->title(__('Connected to :name.', ['name' => $info->name]))->success()
                                    ->body(trim(($info->version ? __('Version :version', ['version' => $info->version]).' · ' : '').__(':count libraries', ['count' => $libraries])))->send();
                            } catch (MediaServerException $e) {
                                Notification::make()->title(__('Could not connect'))->body($e->getMessage())->danger()->send();
                            }
                        }),
                ])->key('serverActions')->columnSpanFull(),
            ]),
        ]);
    }
}
