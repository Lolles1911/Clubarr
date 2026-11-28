<?php

namespace App\Filament\Admin\Resources\MediaServers\Tables;

use App\MediaServers\Data\RemoteUser;
use App\MediaServers\MediaServerException;
use App\Models\Claim;
use App\Models\MediaServer;
use App\Services\ServerSync;
use App\Services\UserImport;
use App\Support\Copy;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;

class MediaServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')
                    ->description(fn (MediaServer $record) => $record->type->label().($record->setting('version') ? ' · '.$record->setting('version') : '')),
                TextColumn::make('url')->label(__('Address'))->limit(40)->color('gray'),
                TextColumn::make('libraries_count')->counts('libraries')->label(__('Libraries')),
                TextColumn::make('accounts_count')->counts('accounts')->label(__('Members'))
                    ->formatStateUsing(fn (MediaServer $record, $state) => $record->capacity ? $state.' / '.$record->capacity : $state),
                IconColumn::make('is_enabled')->label(__('In use'))->boolean(),
                TextColumn::make('last_error')->label(__('Last check'))->wrap()
                    ->state(fn (MediaServer $record) => $record->last_checked_at === null ? '—' : ($record->last_error ?? __('OK, :time', ['time' => $record->last_checked_at->diffForHumans()])))
                    ->color(fn (MediaServer $record) => $record->last_error ? 'danger' : 'success'),
            ])
            ->recordActions([
                Action::make('sync')->label(__('Sync'))->icon(Heroicon::OutlinedArrowPath)
                    ->action(function (MediaServer $record) {
                        try {
                            $sync = app(ServerSync::class);
                            $sync->check($record);
                            $libraries = $sync->syncLibraries($record);
                            $users = $sync->syncUsers($record);
                            Notification::make()->title(__('Synced.'))->success()
                                ->body(__(':libraries libraries; :changed members updated.', ['libraries' => $libraries, 'changed' => $users['changed']])
                                    .($users['missing'] > 0 ? ' '.__(':count members\' users are no longer on the server.', ['count' => $users['missing']]) : ''))
                                ->send();
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
     * Brings users that already exist on the server into Clubarr, many at a
     * time (UserImport): a mail when the server knows their email, else a
     * personal link to send them.
     */
    private static function importAction(): Action
    {
        return Action::make('import')->label(__('Import users'))->icon(Heroicon::OutlinedUserPlus)
            ->modalDescription(__('Users who already exist on this server get a Clubarr account. With an email the server knows (Plex, Silo) they get a mail to choose their password; otherwise (Jellyfin, Emby) you get a personal link to send each of them. Server administrators are not listed.'))
            ->modalSubmitActionLabel(__('Import'))
            ->schema(fn (MediaServer $record) => [
                CheckboxList::make('remote')->label(__('Users on the server'))->required()->bulkToggleable()->searchable()->columns(2)
                    ->options(fn () => collect(self::unlinkedUsers($record))->mapWithKeys(fn (RemoteUser $u) => [$u->id => $u->username.($u->email ? ' ('.$u->email.')' : '')])->all())
                    ->noSearchResultsMessage(__('Nobody to import: every user is linked already.')),
            ])
            ->action(function (MediaServer $record, array $data) {
                $chosen = array_map('strval', (array) ($data['remote'] ?? []));
                $users = array_values(array_filter(self::unlinkedUsers($record), fn (RemoteUser $u) => in_array($u->id, $chosen, true)));
                Cache::forget('clubarr:remote-users:'.$record->id);
                $rows = app(UserImport::class)->import($record, $users);
                self::showImport($rows);
            });
    }

    /** @param list<array{username: string, result: string, link: ?string}> $rows */
    private static function showImport(array $rows): void
    {
        $counts = array_count_values(array_column($rows, 'result'));
        $links = array_values(array_filter($rows, fn ($r) => $r['link'] !== null));
        $lines = [__(':count imported.', ['count' => count($rows)])];
        if ($counts['mailed'] ?? 0) {
            $lines[] = __(':count got a mail to choose their password.', ['count' => $counts['mailed']]);
        }
        if ($counts['linked'] ?? 0) {
            $lines[] = __(':count were linked to the member with their email.', ['count' => $counts['linked']]);
        }
        if ($counts['failed'] ?? 0) {
            $lines[] = __(':count failed; see the log.', ['count' => $counts['failed']]);
        }
        $text = implode("\n", array_map(fn ($r) => $r['username'].': '.$r['link'], $links));
        $notification = Notification::make()->title(__('Import done'))->success()->persistent()
            ->body(new HtmlString(nl2br(e(implode("\n", $lines).($links !== [] ? "\n\n".__('Send each their link (valid :days days, once):', ['days' => Claim::DAYS])."\n".$text : '')))));
        if ($links !== []) {
            $notification->actions([Action::make('copyAll')->label(__('Copy all links'))->button()->alpineClickHandler(Copy::handler($text))]);
        }
        $notification->send();
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
        $users = array_map(fn (array $row) => RemoteUser::fromArray($row), $rows);
        $linked = $server->accounts()->whereNotNull('remote_id')->pluck('remote_id')->all();

        return array_values(array_filter($users, fn (RemoteUser $u) => ! $u->isAdmin && $u->id !== '' && ! in_array($u->id, $linked, true)));
    }
}
