<?php

namespace App\Filament\Admin\Resources\Invitations\Schemas;

use App\Integrations\Discord;
use App\Models\Invitation;
use App\Models\Library;
use App\Models\MediaServer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Start from a template'))->visibleOn('create')->columns(2)
                ->visible(fn (string $operation) => $operation === 'create' && Invitation::where('is_template', true)->exists())
                ->schema([
                    Select::make('template')->label(__('Template'))->placeholder(__('None'))->live()->dehydrated(false)
                        ->options(fn () => Invitation::where('is_template', true)->orderBy('label')->pluck('label', 'id')->map(fn ($l) => $l ?: __('Template'))->all())
                        ->afterStateUpdated(fn (?string $state, Set $set) => self::fillFrom($state, $set))
                        ->helperText(__('Fills in the servers, libraries, limits and greeting. You can still change them.')),
                ]),
            Section::make(__('Who and what'))->columns(2)->schema([
                TextInput::make('label')->label(__('Note'))->maxLength(100)->placeholder(__('e.g. "Mum" or "Friends, summer"'))
                    ->helperText(__('Only you see it.')),
                Select::make('servers')->label(__('Servers'))->multiple()->required()->live()
                    ->options(fn () => MediaServer::where('is_enabled', true)->orderBy('name')->get()->mapWithKeys(fn (MediaServer $s) => [$s->id => $s->name.' ('.$s->type->label().')'])->all()),
                CheckboxList::make('libraries')->label(__('Libraries'))->columnSpanFull()->columns(3)->bulkToggleable()
                    ->options(fn (Get $get) => Library::with('mediaServer')->whereIn('media_server_id', (array) $get('servers'))->orderBy('media_server_id')->orderBy('name')->get()
                        ->mapWithKeys(fn (Library $l) => [$l->media_server_id.':'.$l->external_id => $l->mediaServer->name.': '.$l->name])->all())
                    ->helperText(__('None chosen for a server = all its libraries.'))
                    ->visible(fn (Get $get) => filled($get('servers'))),
                Textarea::make('greeting')->label(__('A few words from you'))->rows(3)->maxLength(1000)->columnSpanFull()
                    ->placeholder(__('e.g. "Hi Mum, here is the access we talked about. Call me if anything is unclear."'))
                    ->helperText(__('Shown on the invitation page and in the mail. Plain text.')),
            ]),
            Section::make(__('Send it'))->columns(2)->visibleOn('create')->hidden(fn (Get $get) => (bool) $get('is_template'))->schema([
                TextInput::make('send_to')->label(__('Mail the link to'))->email()->maxLength(190)
                    ->helperText(__('Optional. You also get the link to copy, share or show as a QR code after saving.')),
                TextInput::make('send_discord')->label(__('Send it on Discord to (user ID)'))->maxLength(22)->regex('/^\d{15,22}$/')
                    ->visible(fn () => Discord::fromSettings() !== null)
                    ->helperText(__('Optional. Discord → right-click the person → Copy User ID (needs Developer Mode). They must share a server with your bot.')),
            ]),
            Section::make(__('Limits'))->columns(['default' => 1, 'md' => 3])->schema([
                DateTimePicker::make('expires_at')->label(__('Link valid until'))->seconds(false)->default(now()->addDays(7)->startOfHour())
                    ->hidden(fn (Get $get) => (bool) $get('is_template'))
                    ->helperText(__('Empty: no end.')),
                TextInput::make('max_uses')->label(__('Can be used'))->numeric()->minValue(1)->maxValue(10000)->default(1)->suffix(__('times'))
                    ->helperText(__('Empty: no limit.')),
                TextInput::make('access_days')->label(__('Access lasts'))->numeric()->minValue(1)->maxValue(36500)->suffix(__('days'))
                    ->helperText(__('Empty: until you remove it.')),
                TextInput::make('max_streams')->label(__('Streams at the same time'))->numeric()->minValue(1)->maxValue(50)
                    ->helperText(__('Needs Tracearr. Empty: no limit.')),
                TextInput::make('request_limit')->label(__('Requests per week'))->numeric()->minValue(0)->maxValue(1000)
                    ->helperText(__('Needs Seerr. Empty: no limit.')),
            ]),
            Toggle::make('is_template')->label(__('Save as a template instead'))->live()->visibleOn('create')
                ->helperText(__('A template is not an invitation: it keeps this set-up, to make invitations from later (also "Many at once" and members\' invitations).')),
        ]);
    }

    /** Fills the form from a template. */
    private static function fillFrom(?string $templateId, Set $set): void
    {
        $template = $templateId ? Invitation::where('is_template', true)->with('mediaServers')->find($templateId) : null;
        if ($template === null) {
            return;
        }
        $set('servers', $template->mediaServers->pluck('id')->map(fn ($id) => (string) $id)->all());
        $set('libraries', $template->mediaServers->flatMap(fn ($s) => collect($template->libraryIdsFor($s) ?? [])->map(fn ($id) => $s->id.':'.$id))->values()->all());
        foreach (['max_uses', 'access_days', 'max_streams', 'request_limit', 'greeting'] as $field) {
            $set($field, $template->{$field});
        }
    }
}
