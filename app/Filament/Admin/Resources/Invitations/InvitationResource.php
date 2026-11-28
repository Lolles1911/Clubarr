<?php

namespace App\Filament\Admin\Resources\Invitations;

use App\Filament\Admin\Resources\Invitations\Pages\CreateInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\EditInvitation;
use App\Filament\Admin\Resources\Invitations\Pages\ListInvitations;
use App\Filament\Admin\Resources\Invitations\Schemas\InvitationForm;
use App\Filament\Admin\Resources\Invitations\Tables\InvitationsTable;
use App\Models\Invitation;
use App\Notifications\InvitationLink;
use App\Services\Audit;
use App\Services\SiteSettings;
use App\Support\Copy;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
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
        return route('filament.app.join', ['code' => $code]);
    }

    /** Shows a new code once: it is not stored and can't be shown again. */
    public static function showCode(string $code): void
    {
        $link = self::link($code);
        Notification::make()
            ->title(__('Copy the invitation link now'))
            ->body(new HtmlString(e(__('It is shown only this once.')).'<br><br>'.e($link)))
            ->success()
            ->persistent()
            ->actions([
                Action::make('copy')->label(__('Copy link'))->button()
                    ->alpineClickHandler(Copy::handler($link)),
            ])
            ->send();
    }

    /** Mails the link, if an address was given; says so when mail is not set up. */
    public static function sendLink(string $code, ?string $email, ?string $greeting): void
    {
        if (blank($email)) {
            return;
        }
        if (app(SiteSettings::class)->get('mail_enabled') !== '1') {
            Notification::make()->title(__('The link was not mailed: set up mail under Settings first.'))->warning()->send();

            return;
        }
        try {
            \Illuminate\Support\Facades\Notification::route('mail', (string) $email)->notify(new InvitationLink(self::link($code), $greeting));
            Audit::log('invitation.mailed', null, ['to' => $email]);
            Notification::make()->title(__('The link was mailed to :email.', ['email' => $email]))->success()->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(__('The link could not be mailed.'))->danger()->send();
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
