<?php

namespace App\Filament\Admin\Pages;

use App\Services\MailTexts;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Icons\Heroicon;

/**
 * The words of the mails to members. Empty fields use Clubarr's own text
 * (shown greyed out). Plain text only; the button and the greeting stay.
 */
class EmailTexts extends SettingsPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'mail-texts';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Mail texts');
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Mail texts');
    }

    protected function keys(): array
    {
        return MailTexts::keys();
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $site = (string) app(SiteSettings::class)->get('site_name');
        $defaults = [
            'welcome' => [__('Welcome to :site', ['site' => $site]), __('Your account is ready. On your page you see your servers, how to open them, and your user name.')],
            'ending_soon' => [__('Your access to :site ends soon', ['site' => $site]), __('Your access ends on :date. Want to keep watching? Ask the person who invited you.', ['date' => '{date}'])],
            'ended' => [__('Your access to :site has ended', ['site' => $site]), __('Your access has ended and your users on the servers are paused. If you get a new invitation, open it while signed in and you are back.')],
        ];

        return $schema->statePath('data')->components(collect(MailTexts::mails())->map(fn (array $mail, string $key) => Section::make($mail['label'])->collapsible()
            ->description(__('You can use: :vars. An empty line starts a new paragraph.', ['vars' => collect($mail['vars'])->map(fn ($v) => '{'.$v.'}')->implode(', ')]))
            ->schema([
                TextInput::make('mail_text_'.$key.'_subject')->label(__('Subject'))->maxLength(150)->placeholder($defaults[$key][0]),
                Textarea::make('mail_text_'.$key.'_body')->label(__('Text'))->rows(4)->maxLength(3000)->placeholder($defaults[$key][1]),
            ]))->values()->all());
    }
}
