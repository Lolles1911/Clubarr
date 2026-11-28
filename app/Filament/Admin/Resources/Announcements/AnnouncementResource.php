<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Models\Announcement;
use App\Models\MediaServer;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Admin → Messages: tell members something by mail, Discord and/or a banner on their page. */
class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 27;

    public static function getModelLabel(): string
    {
        return __('message');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Messages');
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        $mail = app(SiteSettings::class)->get('mail_enabled') === '1';
        $discord = (new DiscordSync)->enabled();

        return $schema->components([
            Section::make()->schema([
                TextInput::make('title')->label(__('Title'))->required()->maxLength(120),
                Textarea::make('body')->label(__('Message'))->required()->rows(6)->maxLength(4000)
                    ->helperText(__('Plain text. An empty line starts a new paragraph.')),
                Select::make('media_server_id')->label(__('To'))->placeholder(__('All members'))
                    ->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->map(fn ($name) => __('Members of :server', ['server' => $name]))->all()),
            ]),
            Section::make(__('How'))->columns(3)->schema([
                Toggle::make('by_mail')->label(__('Mail'))->default($mail)->disabled(! $mail)
                    ->helperText($mail ? null : __('Set up mail under Settings first.')),
                Toggle::make('by_discord')->label(__('Discord message'))->default($discord)->disabled(! $discord)
                    ->helperText($discord ? __('To members who linked Discord.') : __('Set up Discord under Settings first.')),
                Toggle::make('as_banner')->label(__('Banner on their page'))->default(true)->live(),
                DateTimePicker::make('banner_until')->label(__('Banner until'))->seconds(false)->minDate(now())
                    ->default(now()->addDays(3)->startOfHour())->visible(fn (Get $get) => (bool) $get('as_banner'))
                    ->helperText(__('Empty: until you end it.'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Title'))->description(fn (Announcement $r) => mb_strimwidth($r->body, 0, 90, '…'))->wrap(),
                TextColumn::make('mediaServer.name')->visibleFrom('sm')->label(__('To'))->placeholder(__('All members')),
                IconColumn::make('by_mail')->visibleFrom('md')->label(__('Mail'))->boolean(),
                IconColumn::make('by_discord')->visibleFrom('md')->label('Discord')->boolean(),
                TextColumn::make('banner_until')->visibleFrom('sm')->label(__('Banner'))
                    ->state(fn (Announcement $r) => ! $r->as_banner ? '—' : ($r->banner_until === null ? __('Until ended') : ($r->banner_until->isPast() ? __('Ended') : __('Until :date', ['date' => $r->banner_until->translatedFormat('j M H:i')])))),
                TextColumn::make('sent_at')->label(__('Sent'))->since()
                    ->description(fn (Announcement $r) => $r->by_mail || $r->by_discord ? __(':mail mails, :discord Discord', ['mail' => $r->mailed, 'discord' => $r->discorded]) : null),
            ])
            ->recordActions([
                Action::make('endBanner')->label(__('End banner'))->icon('heroicon-m-stop')->color('gray')
                    ->visible(fn (Announcement $r) => $r->as_banner && ($r->banner_until === null || $r->banner_until->isFuture()))
                    ->action(function (Announcement $r) {
                        $r->update(['banner_until' => now()]);
                        Audit::log('message.banner_ended', $r);
                    }),
                DeleteAction::make()->after(fn (Announcement $r) => Audit::log('message.deleted', null, ['title' => $r->title])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAnnouncements::route('/'), 'create' => CreateAnnouncement::route('/create')];
    }
}
