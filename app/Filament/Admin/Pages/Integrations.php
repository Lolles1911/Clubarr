<?php

namespace App\Filament\Admin\Pages;

use App\Integrations\Discord;
use App\Integrations\Seerr;
use App\Integrations\Tautulli;
use App\Integrations\Tracearr;
use App\Services\ComingSoon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Throwable;

/** The other programs Clubarr talks to: Tracearr, Tautulli, Sonarr, Radarr, Seerr and Discord. Each has a Test button. */
class Integrations extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'integrations';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Integrations');
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Integrations');
    }

    protected function keys(): array
    {
        return ['tracearr_url', 'tracearr_api_key', 'tautulli_url', 'tautulli_api_key', 'sonarr_url', 'sonarr_api_key', 'radarr_url', 'radarr_api_key',
            'seerr_url', 'seerr_api_key', 'discord_client_id', 'discord_client_secret', 'discord_bot_token', 'discord_guild_id', 'discord_role_id', 'discord_public_key'];
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Tracearr')->key('tracearr')->columns(2)
                ->description(__('What members watch: My devices, stream limits, numbers and posters.'))->schema([
                    TextInput::make('tracearr_url')->label(__('Address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:3000'),
                    TextInput::make('tracearr_api_key')->label(__('API key'))->password()->revealable()->maxLength(500)->autocomplete('off')
                        ->helperText(__('Tracearr → Settings → Data & API (starts with "trr_pub_"). Stored encrypted; leave empty to keep it.')),
                ])->footerActions([$this->testAction('Tracearr')]),
            Section::make('Tautulli')->key('tautulli')->columns(2)
                ->description(__('For Plex, instead of Tracearr (Tracearr is used when both are set up): what members watch, stream limits, numbers and posters.'))->schema([
                    TextInput::make('tautulli_url')->label(__('Address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:8181'),
                    TextInput::make('tautulli_api_key')->label(__('API key'))->password()->revealable()->maxLength(200)->autocomplete('off')
                        ->helperText(__('Tautulli → Settings → Web Interface → API key. Stored encrypted; leave empty to keep it.')),
                ])->footerActions([$this->testAction('Tautulli')]),
            Section::make('Sonarr & Radarr')->key('arr')->columns(2)
                ->description(__('"Coming soon" on the members\' page: episodes and films expected in the next two weeks.'))->schema([
                    TextInput::make('sonarr_url')->label(__('Sonarr address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:8989'),
                    TextInput::make('sonarr_api_key')->label(__('Sonarr API key'))->password()->revealable()->maxLength(200)->autocomplete('off')->helperText(__('Stored encrypted; leave empty to keep it.')),
                    TextInput::make('radarr_url')->label(__('Radarr address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:7878'),
                    TextInput::make('radarr_api_key')->label(__('Radarr API key'))->password()->revealable()->maxLength(200)->autocomplete('off')->helperText(__('Stored encrypted; leave empty to keep it.')),
                ])->footerActions([$this->testAction('Arr')]),
            Section::make(__('Requests (Overseerr / Jellyseerr)'))->columns(2)->schema([
                TextInput::make('seerr_url')->label(__('Address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:5055'),
                TextInput::make('seerr_api_key')->label(__('API key'))->password()->revealable()->maxLength(500)->autocomplete('off')
                    ->helperText(__('Seerr → Settings → General. Stored encrypted; leave empty to keep it.')),
            ])->footerActions([$this->testAction('Seerr')]),
            Section::make('Discord')->columns(2)
                ->description(__('Members can link Discord and get a role while they have access. Make an application at discord.com/developers, add a bot to your server with "Manage Roles", and add this redirect: :url', ['url' => Discord::redirectUrl()]))->schema([
                    TextInput::make('discord_client_id')->label(__('Application (client) ID'))->maxLength(32)->regex('/^\d*$/'),
                    TextInput::make('discord_client_secret')->label(__('Client secret'))->password()->revealable()->maxLength(200)->autocomplete('off')
                        ->helperText(__('Stored encrypted; leave empty to keep it.')),
                    TextInput::make('discord_bot_token')->label(__('Bot token'))->password()->revealable()->maxLength(200)->autocomplete('off')
                        ->helperText(__('Stored encrypted; leave empty to keep it.')),
                    TextInput::make('discord_guild_id')->label(__('Server ID'))->maxLength(32)->regex('/^\d*$/'),
                    TextInput::make('discord_public_key')->label(__('Public key (for /commands)'))->maxLength(64)->regex('/^([0-9a-fA-F]{64})?$/')
                        ->helperText(__('Optional: /status, /access, /stats and /password in your Discord. Copy the public key from the application, set its "Interactions endpoint URL" to :url, then press "Register commands".', ['url' => url('/api/discord/interactions')])),
                    TextInput::make('discord_role_id')->label(__('Role for members (ID)'))->maxLength(32)->regex('/^\d*$/')
                        ->helperText(__('The bot\'s own role must be above it.')),
                ])->footerActions([$this->testAction('Discord'), Action::make('registerCommands')->label(__('Register commands'))->color('gray')->icon('heroicon-m-command-line')
                ->action(function () {
                    $this->save();
                    try {
                        $discord = Discord::fromSettings();
                        if ($discord === null) {
                            throw new \RuntimeException(__('Set up Discord first.'));
                        }
                        $discord->registerCommands();
                        Notification::make()->title(__('The commands are on your Discord server.'))->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title(__('Discord did not take them: :reason', ['reason' => mb_substr($e->getMessage(), 0, 150)]))->danger()->send();
                    }
                })]),
        ]);
    }

    /**
     * A "Test" button for one integration: saves first, then asks the service.
     *
     * @param  'Tracearr'|'Seerr'|'Discord'|'Tautulli'|'Arr'  $name
     */
    private function testAction(string $name): Action
    {
        return Action::make('test'.$name)->label(__('Test'))->color('gray')->icon('heroicon-m-signal')
            ->action(fn () => $this->testService($name));
    }

    /** @param  'Tracearr'|'Seerr'|'Discord'|'Tautulli'|'Arr'  $name */
    protected function testService(string $name): void
    {
        $this->save();
        $check = match ($name) {
            'Tracearr' => fn () => ($t = Tracearr::fromSettings()) ? __(':count servers', ['count' => count($t->servers())]) : null,
            'Seerr' => fn () => ($s = Seerr::fromSettings()) ? 'v'.$s->version() : null,
            'Discord' => fn () => ($d = Discord::fromSettings()) ? __('bot :name', ['name' => $d->botName()]) : null,
            'Tautulli' => fn () => ($t = Tautulli::fromSettings()) ? 'v'.$t->version() : null,
            'Arr' => fn () => ComingSoon::enabled() ? __(':count coming', ['count' => count(app(ComingSoon::class)->fetch())]) : null,
        };
        try {
            $result = $check();
        } catch (Throwable $e) {
            Notification::make()->title($name)->body(mb_substr($e->getMessage(), 0, 200))->danger()->persistent()->send();

            return;
        }
        $result === null
            ? Notification::make()->title($name.': '.__('not set up'))->warning()->send()
            : Notification::make()->title($name.': '.__('OK').' ('.$result.')')->success()->send();
    }
}
