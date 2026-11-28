<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\ApplySiteLocale;
use App\Integrations\Discord;
use App\Integrations\Seerr;
use App\Integrations\Tracearr;
use App\Jobs\SendAdminAlert;
use App\Models\Invitation;
use App\Services\AdminAlerts;
use App\Services\Audit;
use App\Services\Backups;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Number;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 80;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Settings');
    }

    #[Unexposed]
    public function mount(): void
    {
        $values = app(SiteSettings::class)->all() + SiteSettings::DEFAULTS;
        $values['mail_enabled'] = ($values['mail_enabled'] ?? '0') === '1';
        $values['admin_2fa_required'] = ($values['admin_2fa_required'] ?? '1') !== '0';
        $values['update_check'] = ($values['update_check'] ?? '1') !== '0';
        $values['alerts_events'] = ($values['alerts_events'] ?? null) === null ? array_keys(AdminAlerts::EVENTS) : array_values(array_filter(explode(',', (string) $values['alerts_events']), fn ($e) => isset(AdminAlerts::EVENTS[$e])));
        foreach (SiteSettings::SECRET as $secret) {
            unset($values[$secret]); // never sent to the browser
        }
        $this->form->fill($values);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('settings')->persistTabInQueryString()->tabs([
                Tab::make(__('General'))->key('general')->icon(Heroicon::OutlinedHome)->columns(2)->schema([
                    TextInput::make('site_name')->label(__('Name'))->required()->maxLength(60),
                    Select::make('locale')->label(__('Language'))->options(ApplySiteLocale::LOCALES)->required(),
                    Textarea::make('help_text')->label(__('Getting started text'))->rows(6)->maxLength(5000)->columnSpanFull()
                        ->helperText(__('Shown to members on their page: which apps to use, house rules, where to ask. Plain text.')),
                    Toggle::make('update_check')->label(__('Tell me when a new Clubarr is out'))->columnSpanFull()
                        ->helperText(__('Once a day Clubarr reads the public list of releases on GitHub. Nothing about your Clubarr is sent.')),
                ]),
                Tab::make(__('Security'))->key('security')->icon(Heroicon::OutlinedShieldCheck)->schema([
                    Toggle::make('admin_2fa_required')->label(__('Require two-factor sign-in for admins'))
                        ->helperText(__('Recommended. Admins can change members\' access and see server keys; with this on, a stolen password is not enough. Admins without it set up are asked to on their next sign-in.')),
                ]),
                Tab::make(__('Mail'))->key('mail')->icon(Heroicon::OutlinedEnvelope)->schema([
                    Section::make(__('Mail'))->columns(2)->description(__('For welcome mails, password links and access reminders.'))->schema([
                        Toggle::make('mail_enabled')->label(__('Send mail'))->live()->columnSpanFull(),
                        TextInput::make('mail_host')->label(__('SMTP server'))->maxLength(190)->required(fn (Get $get) => $get('mail_enabled')),
                        TextInput::make('mail_port')->label(__('Port'))->numeric()->minValue(1)->maxValue(65535)->required(fn (Get $get) => $get('mail_enabled')),
                        Select::make('mail_encryption')->label(__('Encryption'))->options(['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => __('None')]),
                        TextInput::make('mail_username')->label(__('User name'))->maxLength(190)->autocomplete('off'),
                        TextInput::make('mail_password')->label(__('Password'))->password()->revealable()->maxLength(500)->autocomplete('new-password')
                            ->helperText(__('Stored encrypted. Leave empty to keep the current one.')),
                        TextInput::make('mail_from_address')->label(__('From address'))->email()->maxLength(190)->required(fn (Get $get) => $get('mail_enabled')),
                        TextInput::make('mail_from_name')->label(__('From name'))->maxLength(100),
                    ])->footerActions([
                        Action::make('testMail')->label(__('Send a test mail to me'))->color('gray')->action(fn () => $this->testMail()),
                    ]),
                ]),
                Tab::make(__('Integrations'))->key('integrations')->icon(Heroicon::OutlinedPuzzlePiece)->schema([
                    Section::make('Tracearr')->key('tracearr')->columns(2)
                        ->description(__('What members watch: My devices, stream limits, numbers and posters.'))->schema([
                            TextInput::make('tracearr_url')->label(__('Address'))->url()->maxLength(255)->placeholder('http://192.168.1.10:3000'),
                            TextInput::make('tracearr_api_key')->label(__('API key'))->password()->revealable()->maxLength(500)->autocomplete('off')
                                ->helperText(__('Tracearr → Settings → Data & API (starts with "trr_pub_"). Stored encrypted; leave empty to keep it.')),
                        ])->footerActions([$this->testAction('Tracearr')]),
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
                            TextInput::make('discord_role_id')->label(__('Role for members (ID)'))->maxLength(32)->regex('/^\d*$/')
                                ->helperText(__('The bot\'s own role must be above it.')),
                        ])->footerActions([$this->testAction('Discord')]),
                ]),
                Tab::make(__('Notifications'))->key('alerts')->icon(Heroicon::OutlinedBellAlert)->schema([
                    Section::make(__('Discord channel for admins'))
                        ->description(__('Clubarr posts to a channel of your choice when something happens. In Discord: the channel → Edit channel → Integrations → Webhooks → New webhook → Copy webhook URL.'))->schema([
                            TextInput::make('alerts_webhook_url')->label(__('Webhook URL'))->password()->revealable()->maxLength(300)->autocomplete('off')
                                ->regex(AdminAlerts::WEBHOOK_PATTERN)->validationMessages(['regex' => __('That is not a Discord webhook URL (https://discord.com/api/webhooks/…).')])
                                ->helperText(__('Stored encrypted; leave empty to keep it.')),
                            CheckboxList::make('alerts_events')->label(__('Tell me when'))->options(AdminAlerts::labels())->columns(1)->bulkToggleable(),
                        ])->footerActions([
                            Action::make('testAlert')->label(__('Send a test message'))->color('gray')->icon('heroicon-m-paper-airplane')->action(fn () => $this->testAlert()),
                            Action::make('forgetWebhook')->label(__('Remove the webhook'))->color('danger')->link()->requiresConfirmation()
                                ->visible(fn () => AdminAlerts::webhook() !== null)
                                ->action(function () {
                                    app(SiteSettings::class)->set(['alerts_webhook_url' => '']);
                                    Audit::log('settings.webhook_removed');
                                    Notification::make()->title(__('Removed.'))->success()->send();
                                }),
                        ]),
                ]),
                Tab::make(__('Backup'))->key('backup')->icon(Heroicon::OutlinedArchiveBox)->schema(Backups::supported() ? [
                    Section::make(__('Download a backup'))
                        ->description(__('The database and the encryption key in one file, locked with a password you choose. Keep the file and the password apart; without the password nobody can read it, not even you.'))
                        ->footerActions([$this->downloadBackupAction()]),
                    Section::make(__('Automatic backups'))
                        ->description(__('Every night Clubarr copies its database to :dir. The newest :keep are kept.', ['dir' => Backups::directory(), 'keep' => Backups::KEEP]))
                        ->schema([Text::make(fn () => collect(Backups::list())->map(fn ($b) => $b['name'].' · '.Number::fileSize($b['size']).' · '.date('Y-m-d H:i', $b['time']))->implode(' | ') ?: __('None yet.'))])
                        ->footerActions([
                            Action::make('backupNow')->label(__('Make one now'))->color('gray')->icon('heroicon-m-plus')
                                ->action(function () {
                                    app(Backups::class)->automatic();
                                    Audit::log('backup.made');
                                    Notification::make()->title(__('Backup made.'))->success()->send();
                                }),
                            $this->restoreAction('restoreAutomatic'),
                        ]),
                    Section::make(__('Restore from a file'))
                        ->description(__('Replaces everything in Clubarr with the backup. The current data is copied to the automatic backups first, and everyone is signed out.'))
                        ->footerActions([$this->restoreAction('restoreFile')]),
                ] : [
                    Text::make(__('Backups here are for the built-in SQLite database. Back up your database with its own tools.')),
                ]),
                Tab::make(__('Waiting list'))->key('waitlist')->icon(Heroicon::OutlinedQueueList)->schema([
                    Select::make('waitlist_template_id')->label(__('Invitations copy'))
                        ->options(fn () => Invitation::where('from_waitlist', false)->latest()->limit(200)->get()->mapWithKeys(fn ($i) => [$i->id => ($i->label ?: $i->code_hint.'…').' · '.$i->mediaServers()->pluck('name')->implode(', ')])->all())
                        ->placeholder(__('Off'))
                        ->helperText(__('When a server with "Members at most" has room, the first in line gets an invitation by mail, valid 48 hours. The public page is /waitlist.').' '.__('Servers, libraries and limits are taken from this invitation. Empty: no waiting list.')),
                ]),
            ]),
        ]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([
                    Action::make('save')->label(__('Save'))->submit('save'),
                ])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['mail_enabled'] = ! empty($data['mail_enabled']) ? '1' : '0';
        $was = app(SiteSettings::class)->get('admin_2fa_required');
        $data['admin_2fa_required'] = ! empty($data['admin_2fa_required']) ? '1' : '0';
        $data['update_check'] = ! empty($data['update_check']) ? '1' : '0';
        // "-" when none is chosen: empty would mean "not set yet", which is all.
        $data['alerts_events'] = implode(',', array_intersect((array) ($data['alerts_events'] ?? []), array_keys(AdminAlerts::EVENTS))) ?: '-';
        foreach (SiteSettings::SECRET as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]); // empty: keep the stored one
            }
        }
        app(SiteSettings::class)->set($data);
        Audit::log('settings.saved', null, ['keys' => array_keys($data)]);
        if ($was !== $data['admin_2fa_required']) {
            Audit::log($data['admin_2fa_required'] === '1' ? 'security.admin_2fa_required' : 'security.admin_2fa_optional');
        }
        Notification::make()->title(__('Saved.'))->success()->send();
    }

    /**
     * A "Test" button for one integration: saves first, then asks the service.
     *
     * @param  'Tracearr'|'Seerr'|'Discord'  $name
     */
    private function testAction(string $name): Action
    {
        return Action::make('test'.$name)->label(__('Test'))->color('gray')->icon('heroicon-m-signal')
            ->action(fn () => $this->testService($name));
    }

    /** @param  'Tracearr'|'Seerr'|'Discord'  $name */
    protected function testService(string $name): void
    {
        $this->save();
        $check = match ($name) {
            'Tracearr' => fn () => ($t = Tracearr::fromSettings()) ? __(':count servers', ['count' => count($t->servers())]) : null,
            'Seerr' => fn () => ($s = Seerr::fromSettings()) ? 'v'.$s->version() : null,
            'Discord' => fn () => ($d = Discord::fromSettings()) ? __('bot :name', ['name' => $d->botName()]) : null,
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

    private function downloadBackupAction(): Action
    {
        return Action::make('downloadBackup')->label(__('Download a backup'))->icon('heroicon-m-arrow-down-tray')
            ->modalDescription(__('Choose a password for the file. It is not saved anywhere: write it down.'))
            ->schema([
                TextInput::make('password')->label(__('Password for the file'))->password()->revealable()->required()->minLength(10)->maxLength(200)->same('password_confirmation'),
                TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required()->dehydrated(false),
            ])
            ->action(function (array $data) {
                $file = app(Backups::class)->export((string) $data['password']);
                Audit::log('backup.downloaded');

                return response()->streamDownload(function () use ($file) {
                    echo $file;
                }, 'clubarr-backup-'.now()->format('Y-m-d').'.clubarr', ['Content-Type' => 'application/octet-stream']);
            });
    }

    /** Restore from an automatic backup or an uploaded file; afterwards everyone, this admin too, signs in again. */
    private function restoreAction(string $name): Action
    {
        $fromFile = $name === 'restoreFile';

        return Action::make($name)->label($fromFile ? __('Restore from a file') : __('Restore one'))->color('danger')->icon('heroicon-m-arrow-uturn-left')
            ->modalDescription(__('Everything in Clubarr is replaced by the backup. The current data is copied to the automatic backups first.'))
            ->schema(array_merge($fromFile ? [
                FileUpload::make('file')->label(__('Backup file (.clubarr)'))->required()->storeFiles(false)->maxSize(512000),
                TextInput::make('password')->label(__('The file\'s password'))->password()->revealable()->required()->maxLength(200),
            ] : [
                Select::make('backup')->label(__('Backup'))->required()
                    ->options(fn () => collect(Backups::list())->mapWithKeys(fn ($b) => [$b['name'] => $b['name'].' · '.date('Y-m-d H:i', $b['time'])])->all()),
            ], [
                Checkbox::make('understood')->label(__('I understand that everything is replaced, and that everyone is signed out.'))->accepted(),
            ]))
            ->action(function (array $data) use ($fromFile) {
                $who = Auth::user()?->email;
                try {
                    $fromFile
                        ? app(Backups::class)->restoreFile($data['file'] instanceof TemporaryUploadedFile ? (string) $data['file']->get() : '', (string) $data['password'])
                        : app(Backups::class)->restoreAutomatic((string) $data['backup']);
                } catch (\RuntimeException $e) {
                    Notification::make()->title(__('Not restored'))->body($e->getMessage())->danger()->persistent()->send();

                    return null;
                }
                // The signed-in user belongs to the old data: sign out (before logging, so no old ID is used).
                Filament::auth()->logout();
                Audit::log('backup.restored', null, ['by' => $who, 'from' => $fromFile ? 'file' : (string) $data['backup']]);
                session()->invalidate();
                session()->regenerateToken();

                return redirect()->to(Filament::getLoginUrl());
            });
    }

    protected function testAlert(): void
    {
        $this->save();
        if (AdminAlerts::webhook() === null) {
            Notification::make()->title(__('Add the webhook URL first.'))->warning()->send();

            return;
        }
        try {
            (new SendAdminAlert(__(':site: test from Clubarr. Notifications work.', ['site' => app(SiteSettings::class)->get('site_name')])))->handle();
            Notification::make()->title(__('Sent. Look in the Discord channel.'))->success()->send();
        } catch (Throwable) {
            Notification::make()->title(__('Discord did not take the message. Check the webhook URL.'))->danger()->send();
        }
    }

    protected function testMail(): void
    {
        $this->save();
        app(SiteMail::class)->apply();
        try {
            Mail::raw(__('This is a test from :site. Mail works.', ['site' => app(SiteSettings::class)->get('site_name')]), function ($message) {
                $message->to(Auth::user()->email)->subject(__('Test mail'));
            });
            Notification::make()->title(__('Sent to :email.', ['email' => Auth::user()->email]))->success()->send();
        } catch (Throwable $e) {
            Notification::make()->title(__('The mail could not be sent'))->body(mb_substr($e->getMessage(), 0, 300))->danger()->persistent()->send();
        }
    }
}
