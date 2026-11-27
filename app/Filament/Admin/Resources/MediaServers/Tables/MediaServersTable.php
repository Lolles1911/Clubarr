<?php

namespace App\Filament\Admin\Resources\MediaServers\Tables;

use App\MediaServers\Data\RemoteUser;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\Audit;
use App\Services\MemberAccounts;
use App\Services\ServerSync;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MediaServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')
                    ->description(fn (MediaServer $record) => $record->type->label()),
                TextColumn::make('url')->label(__('Address'))->limit(40)->color('gray'),
                TextColumn::make('libraries_count')->counts('libraries')->label(__('Libraries')),
                TextColumn::make('accounts_count')->counts('accounts')->label(__('Members')),
                IconColumn::make('is_enabled')->label(__('In use'))->boolean(),
                TextColumn::make('last_error')->label(__('Last check'))->wrap()
                    ->state(fn (MediaServer $record) => $record->last_checked_at === null ? '—' : ($record->last_error ?? __('OK, :time', ['time' => $record->last_checked_at->diffForHumans()])))
                    ->color(fn (MediaServer $record) => $record->last_error ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('check')->label(__('Check'))->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (MediaServer $record) {
                        try {
                            app(ServerSync::class)->check($record);
                            $count = app(ServerSync::class)->syncLibraries($record);
                            Notification::make()->title(__('Connected. :count libraries found.', ['count' => $count]))->success()->send();
                        } catch (MediaServerException $e) {
                            Notification::make()->title(__('Could not connect'))->body($e->getMessage())->danger()->send();
                        }
                    }),
                self::importAction(),
                EditAction::make(),
            ])
            ->emptyStateHeading(__('No media servers yet'))
            ->emptyStateDescription(__('Add your Jellyfin, Plex, Emby or Silo server to start inviting people.'));
    }

    /**
     * Links a user that already exists on the server to a member: an existing
     * one, or a new one who gets a mail to choose their password.
     */
    private static function importAction(): Action
    {
        return Action::make('import')->label(__('Import user'))->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription(__('Link a user that already exists on this server to a member, so they can sign in to Clubarr. Server administrators are not listed.'))
            ->schema(fn (MediaServer $record) => [
                Select::make('remote')->label(__('User on the server'))->required()->searchable()
                    ->options(fn () => collect(self::unlinkedUsers($record))->mapWithKeys(fn (RemoteUser $u) => [$u->id => $u->username.($u->email ? ' ('.$u->email.')' : '')])->all()),
                Select::make('member')->label(__('Existing member'))->searchable()->live()
                    ->options(fn () => User::query()->whereDoesntHave('serverAccounts', fn ($q) => $q->where('media_server_id', $record->id))->orderBy('name')->limit(500)->pluck('email', 'id')->all())
                    ->helperText(__('Or leave empty and make a new member below.')),
                TextInput::make('name')->label(__('Name of the new member'))->maxLength(100)->required(fn (Get $get) => blank($get('member')))->hidden(fn (Get $get) => filled($get('member'))),
                TextInput::make('email')->label(__('Email of the new member'))->email()->maxLength(190)
                    // A plain rule: Filament's unique() would leave out the action's record (the server), not a user.
                    ->rule(Rule::unique('users', 'email'))
                    ->required(fn (Get $get) => blank($get('member')))->hidden(fn (Get $get) => filled($get('member')))
                    ->helperText(__('They get a mail with a link to choose their password.')),
            ])
            ->action(function (MediaServer $record, array $data) {
                $remote = collect(self::unlinkedUsers($record))->first(fn (RemoteUser $u) => $u->id === (string) $data['remote']);
                if ($remote === null) {
                    Notification::make()->title(__('That user can\'t be imported (an administrator, or already linked).'))->danger()->send();

                    return;
                }
                $user = DB::transaction(function () use ($record, $data, $remote) {
                    $user = filled($data['member'] ?? null) ? User::findOrFail((int) $data['member']) : app(MemberAccounts::class)->create((string) $data['name'], (string) $data['email']);
                    ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $record->id, 'remote_id' => $remote->id, 'remote_username' => $remote->username,
                        'status' => $remote->isDisabled ? 'disabled' : 'active']);

                    return $user;
                });
                Cache::forget('clubarr:remote-users:'.$record->id);
                Audit::log('account.imported', $user, ['server' => $record->name, 'remote_username' => $remote->username]);
                Notification::make()->title(__(':user is linked to :member.', ['user' => $remote->username, 'member' => $user->email]))->success()->send();
            });
    }

    /** @return list<RemoteUser> users on the server that no member has, without administrators */
    private static function unlinkedUsers(MediaServer $server): array
    {
        // Cached as plain arrays: the cache does not bring objects back.
        $rows = Cache::remember('clubarr:remote-users:'.$server->id, 60, function () use ($server) {
            try {
                return array_map(fn (RemoteUser $u) => $u->toArray(), $server->driver()->users());
            } catch (MediaServerException) {
                return [];
            }
        });
        $users = array_map(fn (array $row) => RemoteUser::fromArray($row), is_array($rows) ? array_filter($rows, 'is_array') : []);
        $linked = $server->accounts()->whereNotNull('remote_id')->pluck('remote_id')->all();

        return array_values(array_filter($users, fn (RemoteUser $u) => ! $u->isAdmin && $u->id !== '' && ! in_array($u->id, $linked, true)));
    }
}
