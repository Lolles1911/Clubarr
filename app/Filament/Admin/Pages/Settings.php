<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\NavigationGroup;
use App\Http\Middleware\ApplySiteLocale;
use App\Models\Invitation;
use App\Services\Audit;
use App\Services\Backups;
use App\Services\Branding;
use App\Services\LoginGuard;
use App\Services\SingleSignOn;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\ColorPicker;
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

    protected static ?int $navigationSort = 10;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::System;

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
        $values['apply_enabled'] = ($values['apply_enabled'] ?? '0') === '1';
        $values['oidc_enabled'] = ($values['oidc_enabled'] ?? '0') === '1';
        $values['oidc_members'] = ($values['oidc_members'] ?? '0') === '1';
        $values['login_alerts'] = ($values['login_alerts'] ?? '1') !== '0';
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
                    ColorPicker::make('brand_color')->label(__('Accent colour'))->regex('/^#[0-9a-fA-F]{6}$/')
                        ->helperText(__('Buttons and links, for admins and members. Empty: violet.')),
                    FileUpload::make('logo')->label(__('Logo'))->image()->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])->maxSize(300)->storeFiles(false)
                        ->helperText(__('PNG, JPEG or WebP, at most 300 KB; shown instead of the name. A wide logo about 4 times as wide as high looks best.'))
                        ->hintAction(Action::make('removeLogo')->label(__('Remove the logo'))->color('danger')->requiresConfirmation()
                            ->visible(fn () => Branding::logoUrl() !== null)
                            ->action(function () {
                                Branding::removeLogo();
                                Audit::log('settings.logo_removed');
                                $this->redirect(static::getUrl());
                            })),
                    Toggle::make('update_check')->label(__('Tell me when a new Clubarr is out'))->columnSpanFull()
                        ->helperText(__('Once a day Clubarr reads the public list of releases on GitHub. Nothing about your Clubarr is sent.')),
                ]),
                Tab::make(__('Security'))->key('security')->icon(Heroicon::OutlinedShieldCheck)->schema([
                    Section::make(__('Single sign-on (OpenID Connect)'))->columns(2)
                        ->description(__('Sign in with Authentik, Authelia, Keycloak or similar. Only existing accounts can sign in (by a verified email); SSO never makes accounts. Redirect URI for the provider: :url', ['url' => SingleSignOn::redirectUri()]))
                        ->schema([
                            Toggle::make('oidc_enabled')->label(__('On'))->columnSpanFull(),
                            TextInput::make('oidc_issuer')->label(__('Issuer URL'))->url()->maxLength(255)->placeholder('https://auth.example.com/application/o/clubarr')
                                ->regex('~^https://~')->helperText(__('Must be https. Clubarr reads /.well-known/openid-configuration from it.')),
                            TextInput::make('oidc_label')->label(__('Button text'))->maxLength(40)->placeholder(__('Sign in with SSO')),
                            TextInput::make('oidc_client_id')->label(__('Client ID'))->maxLength(200),
                            TextInput::make('oidc_client_secret')->label(__('Client secret'))->password()->revealable()->maxLength(500)->autocomplete('off')
                                ->helperText(__('Stored encrypted; leave empty to keep it.')),
                            Toggle::make('oidc_members')->label(__('Members may use it too'))->columnSpanFull()
                                ->helperText(__('Off: only admins. Signing in this way skips Clubarr\'s own two-factor step, so require two-factor at the provider.')),
                        ]),
                    Toggle::make('admin_2fa_required')->label(__('Require two-factor sign-in for admins'))
                        ->helperText(__('Recommended. Admins can change members\' access and see server keys; with this on, a stolen password is not enough. Admins without it set up are asked to on their next sign-in.')),
                    Toggle::make('login_alerts')->label(__('Mail people when their account is signed in to from a new device'))
                        ->helperText(__('Needs mail. After :count failed sign-ins an account is locked for :minutes minutes either way, and failed sign-ins are in the log.', ['count' => LoginGuard::MAX_FAILURES, 'minutes' => LoginGuard::LOCK_MINUTES])),
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
                Tab::make(__('Invitations'))->key('invites')->icon(Heroicon::OutlinedTicket)->schema([
                    Section::make(__('The invitation page'))->schema([
                        Textarea::make('invite_welcome')->label(__('Welcome text'))->rows(4)->maxLength(3000)
                            ->placeholder(__('e.g. "Welcome to our little cinema! Films and series for family and friends."'))
                            ->helperText(__('Shown on the first step of every invitation, above what they get. Plain text.')),
                    ]),
                    Section::make(__('Reminders'))->schema([
                        TextInput::make('invite_reminder_days')->label(__('Remind after'))->numeric()->minValue(0)->maxValue(60)->suffix(__('days'))
                            ->helperText(__('An invitation mailed to someone and not used gets one reminder mail. 0: no reminders.')),
                    ]),
                    Section::make(__('Apply to join'))->columns(2)
                        ->description(__('A public page (/apply) where people ask to join. You approve under Applications, and they get an invitation by mail.'))->schema([
                            Toggle::make('apply_enabled')->label(__('Open'))->columnSpanFull(),
                            Select::make('apply_template_id')->label(__('Template for approved applications'))->placeholder(__('Choose when approving'))
                                ->options(fn () => Invitation::where('is_template', true)->orderBy('label')->pluck('label', 'id')->map(fn ($l) => $l ?: __('Template'))->all()),
                        ]),
                    Section::make(__('Members invite friends'))->columns(2)
                        ->description(__('Members get "Invite a friend": one person per invitation, valid 7 days, with the servers, libraries and limits of the template. You see who invited whom.'))->schema([
                            TextInput::make('member_invites_per_month')->label(__('Invitations per member a month'))->numeric()->minValue(0)->maxValue(50)
                                ->helperText(__('0: off.')),
                            Select::make('member_invite_template_id')->label(__('Template'))->placeholder(__('None (off)'))
                                ->options(fn () => Invitation::where('is_template', true)->orderBy('label')->pluck('label', 'id')->map(fn ($l) => $l ?: __('Template'))->all())
                                ->helperText(__('Make one under Invitations: "Save as a template instead".')),
                        ]),
                ]),
                Tab::make(__('Automation'))->key('automation')->icon(Heroicon::OutlinedCog)->schema([
                    Section::make(__('Inactive members'))->schema([
                        TextInput::make('inactive_days')->label(__('Pause members who watched nothing for'))->numeric()->minValue(0)->maxValue(3650)->suffix(__('days'))
                            ->helperText(__('They get a mail a week before. Paused frees their seat. Last watched comes from Jellyfin/Emby, or from Tracearr (needed for Plex). 0: off.')),
                    ]),
                    Section::make(__('After access ends'))->schema([
                        TextInput::make('cleanup_days')->label(__('Delete their users on the servers after'))->numeric()->minValue(0)->maxValue(3650)->suffix(__('days'))
                            ->helperText(__('When access ends, users are paused. This deletes them on the servers (Plex: the share) after the days set; the Clubarr account stays. 0: never.')),
                    ]),
                    Section::make(__('Old data'))->columns(3)->description(__('Removed every night after the days set. 0: keep.'))->schema([
                        TextInput::make('retention_log_days')->label(__('The log'))->numeric()->minValue(0)->maxValue(3650)->suffix(__('days')),
                        TextInput::make('retention_invitations_days')->label(__('Invitations that ended or were used up'))->numeric()->minValue(0)->maxValue(3650)->suffix(__('days'))
                            ->helperText(__('Not templates.')),
                        TextInput::make('retention_requests_days')->label(__('Decided requests, closed reports, old waiting list'))->numeric()->minValue(0)->maxValue(3650)->suffix(__('days')),
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
        $logo = $data['logo'] ?? null;
        unset($data['logo']);
        if ($logo instanceof TemporaryUploadedFile) {
            try {
                Branding::storeLogo((string) $logo->get());
                Audit::log('settings.logo_changed');
            } catch (\RuntimeException $e) {
                Notification::make()->title($e->getMessage())->danger()->send();

                return;
            }
        }
        $data['mail_enabled'] = ! empty($data['mail_enabled']) ? '1' : '0';
        $was = app(SiteSettings::class)->get('admin_2fa_required');
        $data['admin_2fa_required'] = ! empty($data['admin_2fa_required']) ? '1' : '0';
        $data['update_check'] = ! empty($data['update_check']) ? '1' : '0';
        $data['apply_enabled'] = ! empty($data['apply_enabled']) ? '1' : '0';
        $data['oidc_enabled'] = ! empty($data['oidc_enabled']) ? '1' : '0';
        $data['oidc_members'] = ! empty($data['oidc_members']) ? '1' : '0';
        $data['login_alerts'] = ! empty($data['login_alerts']) ? '1' : '0';
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
            ->visible(fn () => $fromFile || Backups::list() !== [])
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
