<?php

namespace App\Filament\Admin\Resources\Invitations;

use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\EditInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Admin\Resources\Invitations\Schemas\InvitationForm;
use App\Filament\Admin\Resources\Invitations\Tables\InvitationsTable;
use App\Models\Invitation;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Js;

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
        return route('filament.app.join', ['code' => $code]);
    }

    /** Shows a new code once: it is not stored and can't be shown again. */
    public static function showCode(string $code): void
    {
        $link = self::link($code);
        Notification::make()
            ->title(__('Copy the invitation link now'))
            ->body(__('It is shown only this once.')."\n\n".$link)
            ->success()
            ->persistent()
            ->actions([
                Action::make('copy')->label(__('Copy link'))->button()
                    // Js::from() is safe inside an HTML attribute (no raw quotes).
                    ->alpineClickHandler('navigator.clipboard.writeText('.Js::from($link).'); $tooltip('.Js::from(__('Copied')).')'),
            ])
            ->send();
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
