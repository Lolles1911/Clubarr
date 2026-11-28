<?php

namespace App\Filament\Admin\Resources\Invitations;

use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\EditInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Admin\Resources\Invitations\Schemas\InvitationForm;
use App\Filament\Admin\Resources\Invitations\Tables\InvitationsTable;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationMaker;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class InvitationResource extends Resource
{
    protected static ?string $model = Invitation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('invitation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Invitations');
    }

    public static function form(Schema $schema): Schema
    {
        return InvitationForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvitationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvitations::route('/'),
            'create' => CreateInvitation::route('/create'),
            'edit' => EditInvitation::route('/{record}/edit'),
        ];
    }

    public static function link(string $code): string
    {
        return InvitationMaker::link($code);
    }

    /** Shows a new code once, with Copy, Share and a QR code: it is not stored and can't be shown again. */
    public static function showCode(string $code): void
    {
        Notification::make()
            ->title(__('Here is the invitation link'))
            ->body(self::shareBox($code))
            ->success()
            ->persistent()
            ->send();
    }

    public static function shareBox(string $code): HtmlString
    {
        $site = (string) app(SiteSettings::class)->get('site_name');

        return new HtmlString('<p style="margin-bottom:.6rem">'.e(__('It is shown only this once.')).'</p>'.Blade::render(
            '<x-share-link :link="$link" :title="$title" :text="$text" />',
            ['link' => self::link($code), 'title' => $site, 'text' => __('You are invited to :site! Open the link to join:', ['site' => $site])],
        ));
    }

    /** Sends the link by mail and/or Discord, and says how it went. */
    public static function sendLink(Invitation $invitation, string $code, ?string $email, ?string $discordId): void
    {
        foreach (app(InvitationMaker::class)->send($invitation, $code, $email, $discordId, Auth::user() instanceof User ? Auth::user() : null) as $result) {
            Notification::make()->title($result['text'])->{$result['ok'] ? 'success' : 'warning'}()->send();
        }
    }

    /**
     * The form's "libraries" (["serverId:libraryId", ...]) as each chosen
     * server's library IDs; a server with none chosen gets all.
     *
     * @return array<int, array{library_ids: ?string}>
     */
    public static function pivotFor(array $serverIds, array $libraries): array
    {
        $pivot = [];
        foreach ($serverIds as $serverId) {
            $ids = [];
            foreach ($libraries as $value) {
                [$sid, $lid] = array_pad(explode(':', (string) $value, 2), 2, '');
                if ((int) $sid === (int) $serverId && $lid !== '') {
                    $ids[] = $lid;
                }
            }
            $pivot[(int) $serverId] = ['library_ids' => $ids === [] ? null : json_encode(array_values(array_unique($ids)))];
        }

        return $pivot;
    }
}
