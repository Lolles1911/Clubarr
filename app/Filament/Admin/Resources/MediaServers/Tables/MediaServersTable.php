<?php

namespace App\Filament\Admin\Resources\MediaServers\Tables;

use App\MediaServers\Data\RemoteUser;
use App\MediaServers\MediaServerException;
use App\Models\Claim;
use App\Models\MediaServer;
use App\Services\Audit;
use App\Services\ExistingUsers;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\HtmlString;
use Throwable;

class MediaServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')
                    ->description(fn (MediaServer $record) => $record->type->label().($record->setting('version') ? ' · '.$record->setting('version') : '')),
                TextColumn::make('url')->visibleFrom('lg')->label(__('Address'))->limit(40)->color('gray'),
                TextColumn::make('libraries_count')->visibleFrom('md')->counts('libraries')->label(__('Libraries')),
                TextColumn::make('accounts_count')->counts('accounts')->label(__('Members'))
                    ->formatStateUsing(fn (MediaServer $record, $state) => $record->capacity ? $state.' / '.$record->capacity : $state)
                    ->description(fn (MediaServer $record) => (int) $record->setting('unlinked', 0) > 0 ? __(':count not in Clubarr', ['count' => (int) $record->setting('unlinked')]) : null),
                IconColumn::make('is_enabled')->visibleFrom('sm')->label(__('In use'))->boolean(),
                TextColumn::make('last_error')->visibleFrom('md')->label(__('Last check'))->wrap()
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
                                    .($users['removed'] > 0 ? ' '.__(':count users were deleted on the server; their access is removed here.', ['count' => $users['removed']]) : '')
                                    .($users['held'] ? ' '.__(':count users seem gone at once; nothing was removed. Check the server.', ['count' => $users['missing']]) : '')
                                    .($users['unlinked'] > 0 ? ' '.__(':count users on the server are not in Clubarr (Import users).', ['count' => $users['unlinked']]) : ''))
                                ->send();
                        } catch (MediaServerException $e) {
                            Notification::make()->title(__('Could not connect'))->body($e->getMessage())->danger()->send();
                        }
                    }),
                self::importAction(),
                self::cleanUpAction(),
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

    /**
     * Deletes users on the server that no member has, after the admin chose
     * them. The list is read again from the server first: only users still
     * not linked and not administrators are deleted (the drivers refuse
     * administrators too).
     */
    private static function cleanUpAction(): Action
    {
        return Action::make('cleanUp')->label(__('Clean up'))->icon(Heroicon::OutlinedTrash)->color('danger')
            ->visible(fn (MediaServer $record) => (int) $record->setting('unlinked', 0) > 0)
            ->modalHeading(fn (MediaServer $record) => __('Users on :server not in Clubarr', ['server' => $record->name]))
            ->modalDescription(__('Choose the users to delete on the server (Plex: the share is removed). Server administrators are not listed. Want to keep someone? Use Import users instead. This can\'t be undone.'))
            ->modalSubmitActionLabel(__('Delete the chosen'))
            ->schema(fn (MediaServer $record) => [
                CheckboxList::make('remote')->label(__('Users on the server'))->required()->bulkToggleable()->searchable()->columns(1)
                    ->options(fn () => collect(self::unlinkedUsers($record))->sortBy(fn (RemoteUser $u) => $u->lastSeen ?? 0)
                        ->mapWithKeys(fn (RemoteUser $u) => [$u->id => $u->username.($u->email ? ' ('.$u->email.')' : '').' · '
                            .($u->lastSeen ? __('last seen :time', ['time' => Carbon::createFromTimestamp($u->lastSeen)->diffForHumans()]) : __('never seen'))])->all()),
            ])
            ->action(function (MediaServer $record, array $data) {
                $chosen = array_map('strval', (array) ($data['remote'] ?? []));
                Cache::forget('clubarr:remote-users:'.$record->id);
                $deleted = $failed = 0;
                foreach (self::unlinkedUsers($record) as $user) {
                    if (! in_array($user->id, $chosen, true)) {
                        continue;
                    }
                    try {
                        $record->driver()->delete($user->id, $user->username);
                        Audit::log('server.unlinked_deleted', $record, ['remote_username' => $user->username]);
                        $deleted++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                    }
                }
                Cache::forget('clubarr:remote-users:'.$record->id);
                try {
                    app(ServerSync::class)->syncUsers($record);
                } catch (Throwable) {
                }
                Notification::make()->title(__(':count deleted on the server.', ['count' => $deleted]))->{$failed > 0 ? 'warning' : 'success'}()
                    ->body($failed > 0 ? __(':count failed; see the log.', ['count' => $failed]) : null)->send();
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
        return app(ExistingUsers::class)->unlinked($server);
    }
}
