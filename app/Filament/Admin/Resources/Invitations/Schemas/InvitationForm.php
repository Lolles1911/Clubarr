<?php

namespace App\Filament\Admin\Resources\Invitations\Schemas;

use App\Models\Library;
use App\Models\MediaServer;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class InvitationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
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
            ]),
            Section::make(__('Limits'))->columns(3)->schema([
                DateTimePicker::make('expires_at')->label(__('Link valid until'))->seconds(false)->default(now()->addDays(7)->startOfHour())
                    ->helperText(__('Empty: no end.')),
                TextInput::make('max_uses')->label(__('Can be used'))->numeric()->minValue(1)->maxValue(10000)->default(1)->suffix(__('times'))
                    ->helperText(__('Empty: no limit.')),
                TextInput::make('access_days')->label(__('Access lasts'))->numeric()->minValue(1)->maxValue(36500)->suffix(__('days'))
                    ->helperText(__('Empty: until you remove it.')),
            ]),
        ]);
    }
}
