<?php

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\ApplySiteLocale;
use App\Services\Audit;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
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
        unset($values['mail_password']); // never sent to the browser
        $this->form->fill($values);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make(__('Site'))->columns(2)->schema([
                TextInput::make('site_name')->label(__('Name'))->required()->maxLength(60),
                Select::make('locale')->label(__('Language'))->options(ApplySiteLocale::LOCALES)->required(),
                Textarea::make('help_text')->label(__('Getting started text'))->rows(6)->maxLength(5000)->columnSpanFull()
                    ->helperText(__('Shown to members on their page: which apps to use, house rules, where to ask. Plain text.')),
            ]),
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
                    Action::make('testMail')->label(__('Send a test mail to me'))->color('gray')->action(fn () => $this->testMail()),
                ])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $data['mail_enabled'] = ! empty($data['mail_enabled']) ? '1' : '0';
        if (blank($data['mail_password'] ?? null)) {
            unset($data['mail_password']);
        }
        app(SiteSettings::class)->set($data);
        Audit::log('settings.saved', null, ['keys' => array_keys($data)]);
        Notification::make()->title(__('Saved.'))->success()->send();
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
