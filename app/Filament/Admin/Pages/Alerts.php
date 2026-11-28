<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\NavigationGroup;
use App\Services\AdminAlerts;
use App\Services\AlertChannels;
use App\Services\Audit;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use RuntimeException;

/**
 * Admin alerts: what to be told about, and where (Discord, ntfy, Telegram,
 * Gotify, a webhook). Addresses and tokens are stored encrypted and never
 * sent back to the browser; empty keeps the stored one.
 *
 * @property-read Schema $form
 */
class Alerts extends Page
{
    /** Secret fields per channel, for "Remove". */
    private const CHANNEL_KEYS = [
        'discord' => ['alerts_webhook_url'],
        'ntfy' => ['alerts_ntfy_url', 'alerts_ntfy_token'],
        'telegram' => ['alerts_telegram_token', 'alerts_telegram_chat'],
        'gotify' => ['alerts_gotify_url', 'alerts_gotify_token'],
        'webhook' => ['alerts_hook_url', 'alerts_hook_secret'],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 12;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::System;

    protected static ?string $slug = 'alerts';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Notifications');
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Notifications');
    }

    #[Unexposed]
    public function mount(): void
    {
        $s = app(SiteSettings::class);
        $events = $s->get('alerts_events');
        $this->form->fill([
            'alerts_events' => $events === null ? array_keys(AdminAlerts::EVENTS) : array_values(array_filter(explode(',', $events), fn ($e) => isset(AdminAlerts::EVENTS[$e]))),
            'alerts_telegram_chat' => $s->get('alerts_telegram_chat'),
            'alerts_gotify_url' => $s->get('alerts_gotify_url'),
        ]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $kept = __('Stored encrypted; leave empty to keep it.');
        $secret = fn (string $key, string $label) => TextInput::make($key)->label($label)->password()->revealable()->maxLength(500)->autocomplete('off')->helperText($kept);

        return $schema->statePath('data')->components([
            Section::make(__('Tell me when'))->schema([
                CheckboxList::make('alerts_events')->hiddenLabel()->options(AdminAlerts::labels())->columns(1)->bulkToggleable(),
            ]),
            Section::make('Discord')->collapsible()
                ->description(__('In Discord: the channel → Edit channel → Integrations → Webhooks → New webhook → Copy webhook URL.'))
                ->schema([
                    $secret('alerts_webhook_url', __('Webhook URL'))->maxLength(300)
                        ->regex(AdminAlerts::WEBHOOK_PATTERN)->validationMessages(['regex' => __('That is not a Discord webhook URL (https://discord.com/api/webhooks/…).')]),
                ])->footerActions([$this->removeAction('discord')]),
            Section::make('ntfy')->collapsible()->columns(2)
                ->description(__('The address of the topic, e.g. https://ntfy.sh/a-long-random-name. Anyone who knows a public topic can read it, so pick a long name, or use your own ntfy with a token.'))
                ->schema([
                    $secret('alerts_ntfy_url', __('Topic address'))->maxLength(300)->regex('~^https?://[^\s?#@]+/[\w-]{1,64}/?$~'),
                    $secret('alerts_ntfy_token', __('Access token (optional)')),
                ])->footerActions([$this->removeAction('ntfy')]),
            Section::make('Telegram')->collapsible()->columns(2)
                ->description(__('Make a bot with @BotFather and copy its token. Add the bot to a chat or channel, and give its ID (e.g. -1001234567890 or @mychannel).'))
                ->schema([
                    $secret('alerts_telegram_token', __('Bot token'))->regex(AlertChannels::TELEGRAM_TOKEN),
                    TextInput::make('alerts_telegram_chat')->label(__('Chat ID'))->maxLength(70)->regex(AlertChannels::TELEGRAM_CHAT),
                ])->footerActions([$this->removeAction('telegram')]),
            Section::make('Gotify')->collapsible()->columns(2)
                ->description(__('Your Gotify address and an application token (Gotify → Apps → Create application).'))
                ->schema([
                    TextInput::make('alerts_gotify_url')->label(__('Address'))->url()->maxLength(255)->placeholder('https://gotify.example.com'),
                    $secret('alerts_gotify_token', __('Application token')),
                ])->footerActions([$this->removeAction('gotify')]),
            Section::make(__('Webhook'))->collapsible()->columns(2)
                ->description(__('Clubarr posts JSON {"site", "text", "time"} to the address (no redirects). With a secret, the header X-Clubarr-Signature is sha256= and the HMAC-SHA256 of the body, so you can check it came from Clubarr.'))
                ->schema([
                    $secret('alerts_hook_url', __('Address'))->maxLength(300)->regex('~^https?://[^\s?#@]+$~'),
                    $secret('alerts_hook_secret', __('Secret (optional)')),
                ])->footerActions([$this->removeAction('webhook')]),
        ]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('save')->label(__('Save'))->submit('save'),
                    Action::make('testAlert')->label(__('Send a test message'))->color('gray')->icon('heroicon-m-paper-airplane')->action(fn () => $this->test()),
                ])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        // "-" when none is chosen: empty would mean "not set yet", which is all.
        $data['alerts_events'] = implode(',', array_intersect((array) ($data['alerts_events'] ?? []), array_keys(AdminAlerts::EVENTS))) ?: '-';
        foreach (SiteSettings::SECRET as $key) {
            if (array_key_exists($key, $data) && blank($data[$key])) {
                unset($data[$key]); // empty: keep the stored one
            }
        }
        $data = collect($data)->map(fn ($v) => $v === null ? '' : (string) $v)->all();
        app(SiteSettings::class)->set($data);
        Audit::log('settings.alerts_saved', null, ['channels' => AlertChannels::configured()]);
        foreach (SiteSettings::SECRET as $key) {
            unset($this->data[$key]);
        }
        Notification::make()->title(__('Saved.'))->success()->send();
    }

    private function test(): void
    {
        $this->save();
        $channels = AlertChannels::configured();
        if ($channels === []) {
            Notification::make()->title(__('Set up a channel first.'))->warning()->send();

            return;
        }
        $text = __(':site: test from Clubarr. Notifications work.', ['site' => app(SiteSettings::class)->get('site_name')]);
        foreach ($channels as $channel) {
            try {
                AlertChannels::send($channel, $text);
                Notification::make()->title(__('Sent to :channel.', ['channel' => AlertChannels::labels()[$channel]]))->success()->send();
            } catch (RuntimeException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();
            }
        }
    }

    private function removeAction(string $channel): Action
    {
        return Action::make('remove'.ucfirst($channel))->label(__('Remove'))->color('danger')->link()->requiresConfirmation()
            ->visible(fn () => in_array($channel, AlertChannels::configured(), true))
            ->action(function () use ($channel) {
                app(SiteSettings::class)->set(array_fill_keys(self::CHANNEL_KEYS[$channel], ''));
                Audit::log('settings.alert_channel_removed', null, ['channel' => $channel]);
                Notification::make()->title(__('Removed.'))->success()->send();
            });
    }
}
