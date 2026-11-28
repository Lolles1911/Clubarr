<?php

namespace App\Filament\Admin\Resources\MediaServers\Schemas;

use App\Enums\ServerType;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Services\PlexFinder;
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

class MediaServerForm
{
    /**
     * Looks for Plex: through plex.tv with the owner's token when there is
     * one, else on the admin's network. The result stays in the session
     * (the token itself does not) for the choice that follows.
     */
    private static function findPlex(string $token): void
    {
        $finder = app(PlexFinder::class);
        $network = PlexFinder::networkOf(request()->getHost()) ?? PlexFinder::networkOf(parse_url((string) config('app.url'), PHP_URL_HOST) ?: null);
        if ($token !== '') {
            try {
                [$how, $found] = ['account', $finder->fromAccount($token)];
            } catch (MediaServerException $e) {
                [$how, $found] = [in_array($e->getCode(), [401, 403], true) ? 'rejected' : 'unreachable', []];
            }
        } else {
            [$how, $found] = $network !== null
                ? ['network', array_map(fn ($s) => ['name' => 'Plex', 'uri' => $s['uri'], 'local' => true, 'ok' => true, 'ms' => null, 'version' => $s['version']], $finder->scan($network))]
                : ['none', []];
        }
        session()->put(['admin.plex.found' => $found, 'admin.plex.how' => $how, 'admin.plex.network' => $network]);
    }

    /** @return list<array{name: string, uri: string, local: bool, ok: bool, ms: int|null, version: string}> */
    private static function foundPlex(): array
    {
        $found = session('admin.plex.found');

        return is_array($found) ? array_values(array_filter($found, fn ($s) => is_array($s) && is_string($s['uri'] ?? null) && isset($s['ok']))) : [];
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
                        ServerType::Plex->value => __('The server owner\'s Plex token (see Plex\'s "Finding an authentication token" guide). Paste it, then press Find Plex.'),
                        ServerType::Silo->value => __('A Silo API key of an admin account, with full access.'),
                        default => __('Dashboard → API Keys on the server.'),
                    }),
                TextInput::make('capacity')->label(__('Members at most'))->numeric()->minValue(1)->maxValue(100000)
                    ->helperText(__('Empty: no limit. When full, invitations wait, and the waiting list (Settings) takes over.')),
                Toggle::make('is_enabled')->label(__('In use'))->default(true)
                    ->helperText(__('Off: new invitations can\'t give access to it. Existing users are not changed.')),
                Actions::make([
                    // Plex: look for servers on the admin's network; the address can always be typed instead.
                    Action::make('plexFind')->label(__('Find Plex'))->color('warning')->icon('heroicon-m-magnifying-glass')
                        ->visible(fn (Get $get) => $get('type') === ServerType::Plex->value)
                        ->modalHeading(__('Your Plex servers'))->modalSubmitActionLabel(__('Use this one'))
                        ->fillForm(function (Get $get, ?MediaServer $record) {
                            self::findPlex(filled($get('api_key')) ? (string) $get('api_key') : (string) $record?->api_key);

                            return ['server' => collect(self::foundPlex())->firstWhere('ok', true)['uri'] ?? null];
                        })
                        ->schema([
                            Select::make('server')->label(__('Found'))->required()
                                ->options(fn () => collect(self::foundPlex())->mapWithKeys(fn ($s) => [$s['uri'] => implode(' · ', array_filter([
                                    $s['name'], $s['uri'], $s['version'] !== '' ? 'Plex '.$s['version'] : null, $s['local'] ? __('local') : null,
                                    $s['ok'] ? ($s['ms'] !== null ? $s['ms'].' ms' : null) : __('not reachable from Clubarr'),
                                ]))])->all())
                                ->disableOptionWhen(fn (string $value) => ! (collect(self::foundPlex())->firstWhere('uri', $value)['ok'] ?? false))
                                ->helperText(fn () => match (session('admin.plex.how')) {
                                    'account' => self::foundPlex() === [] ? __('Your Plex account has no server of its own.') : __('From your Plex account, tried from Clubarr. Choose one it can reach.'),
                                    'rejected' => __('plex.tv did not accept the token. Check it, or empty the field to look on the network instead.'),
                                    'unreachable' => __('plex.tv could not be reached. Please try again.'),
                                    'network' => self::foundPlex() === [] ? __('No Plex found on :network.x. Type the address yourself, e.g. http://192.168.1.10:32400.', ['network' => session('admin.plex.network')])
                                        : __('Choose one; the address is filled in. Then add the token and press Test.'),
                                    default => __('Clubarr could not tell your network. Paste the token first, or type the address yourself, e.g. http://192.168.1.10:32400.'),
                                }),
                        ])
                        ->action(function (array $data, Set $set, Get $get) {
                            $server = collect(self::foundPlex())->first(fn ($s) => $s['ok'] && $s['uri'] === (string) $data['server']);
                            if ($server === null) {
                                return;
                            }
                            $set('url', $server['uri']);
                            if (blank($get('name')) && $server['name'] !== 'Plex') {
                                $set('name', $server['name']);
                            }
                            $fromAccount = session('admin.plex.how') === 'account';
                            session()->forget(['admin.plex.found', 'admin.plex.how', 'admin.plex.network']);
                            Notification::make()->title($fromAccount ? __('Filled in. Press Test, then save.') : __('Address filled in. Add the token, then press Test.'))->success()->send();
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
