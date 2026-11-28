<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\MemberAccounts;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Throwable;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('serverAccounts.mediaServer'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')->description(fn (User $record) => $record->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('servers')->label(__('Servers'))->badge()
                    ->state(fn (User $record) => $record->serverAccounts->map(fn ($a) => $a->mediaServer->name)->all())
                    ->color(fn (User $record, string $state) => $record->serverAccounts->first(fn ($a) => $a->mediaServer->name === $state)?->status->color() ?? 'gray'),
                TextColumn::make('access_expires_at')->visibleFrom('sm')->label(__('Access ends'))->date('j M Y')->placeholder(__('No end'))->sortable()
                    ->color(fn (User $record) => $record->hasAccessExpired() ? 'danger' : null),
                TextColumn::make('role')->visibleFrom('md')->label(__('Role'))->badge()
                    ->state(fn (User $record) => $record->disabled_at ? __('Disabled') : ($record->is_admin ? __('Admin') : __('Member')))
                    ->color(fn (User $record) => $record->disabled_at ? 'danger' : ($record->is_admin ? 'primary' : 'gray')),
                TextColumn::make('last_login_at')->visibleFrom('md')->label(__('Last sign-in'))->since()->placeholder('—')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin')->label(__('Admins')),
                TernaryFilter::make('disabled')->label(__('Disabled'))->nullable()->attribute('disabled_at'),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('extend')->label(__('Change access end'))->icon(Heroicon::OutlinedCalendarDays)
                        ->schema([DateTimePicker::make('until')->label(__('Access ends'))->seconds(false)->helperText(__('Empty: no end.'))])
                        ->fillForm(fn (User $record) => ['until' => $record->access_expires_at])
                        ->action(function (User $record, array $data) {
                            $record->forceFill(['access_expires_at' => $data['until'] ?? null, 'expiry_warned_at' => null])->save();
                            Audit::log('member.access_changed', $record, ['until' => $data['until'] ?? null]);
                            if (! $record->hasAccessExpired() && $record->disabled_at === null) {
                                self::report(app(AccessManager::class)->enableAll($record), __('Access changed.'));
                            } else {
                                Notification::make()->title(__('Access changed.'))->success()->send();
                            }
                        }),
                    Action::make('disable')->label(__('Disable'))->icon(Heroicon::OutlinedPauseCircle)->color('warning')
                        ->visible(fn (User $record) => $record->disabled_at === null && ! $record->is(Auth::user()))
                        ->requiresConfirmation()->modalDescription(__('The member can\'t sign in, and their users on the servers are paused.'))
                        ->action(function (User $record) {
                            $record->forceFill(['disabled_at' => now()])->save();
                            Audit::log('member.disabled', $record);
                            self::report(app(AccessManager::class)->disableAll($record, 'disabled by admin'), __('Disabled.'));
                            app(DiscordSync::class)->sync($record);
                        }),
                    Action::make('enable')->label(__('Enable'))->icon(Heroicon::OutlinedPlayCircle)->color('success')
                        ->visible(fn (User $record) => $record->disabled_at !== null)
                        ->action(function (User $record) {
                            $record->forceFill(['disabled_at' => null])->save();
                            Audit::log('member.enabled', $record);
                            $failed = $record->hasAccessExpired() ? [] : app(AccessManager::class)->enableAll($record);
                            self::report($failed, __('Enabled.'));
                            app(DiscordSync::class)->sync($record);
                        }),
                    Action::make('passwordLink')->label(__('Send password link'))->icon(Heroicon::OutlinedEnvelope)
                        ->requiresConfirmation()->modalDescription(__('The member gets a mail with a link to choose a new password for Clubarr.'))
                        ->action(function (User $record) {
                            app(MemberAccounts::class)->sendPasswordLink($record);
                            Audit::log('member.password_link', $record);
                            Notification::make()->title(__('Sent.'))->success()->send();
                        }),
                    EditAction::make(),
                    Action::make('removeServer')->label(__('Remove from a server'))->icon(Heroicon::OutlinedServerStack)->color('danger')
                        ->visible(fn (User $record) => $record->serverAccounts->isNotEmpty())
                        ->modalDescription(__('Their user on that server is deleted (Plex: the share). Their Clubarr account and other servers stay.'))
                        ->schema([
                            Select::make('account')->label(__('Server'))->required()
                                ->options(fn (User $record) => $record->serverAccounts->mapWithKeys(fn ($a) => [$a->id => $a->mediaServer->name.($a->remote_username ? ' · '.$a->remote_username : '')])->all()),
                        ])
                        ->action(function (User $record, array $data) {
                            // Only one of this member's own accounts.
                            $account = $record->serverAccounts()->with('mediaServer')->find($data['account']);
                            if ($account === null) {
                                return;
                            }
                            try {
                                app(AccessManager::class)->removeOne($account);
                                Notification::make()->title(__('Removed from :server.', ['server' => $account->mediaServer->name]))->success()->send();
                            } catch (Throwable $e) {
                                Notification::make()->title(__('Could not remove it'))->body(mb_substr($e->getMessage(), 0, 300))->danger()->send();
                            }
                        }),
                    Action::make('remove')->label(__('Remove'))->icon(Heroicon::OutlinedTrash)->color('danger')
                        ->visible(fn (User $record) => ! $record->is(Auth::user()))
                        ->requiresConfirmation()->modalDescription(__('Their users are removed from the servers (Plex: the share), and their Clubarr account is deleted. This can\'t be undone.'))
                        ->action(function (User $record) {
                            $failed = app(AccessManager::class)->removeAll($record);
                            // Takes the Discord role when no server user is left.
                            app(DiscordSync::class)->sync($record);
                            if ($failed !== []) {
                                self::report($failed, '');

                                return;
                            }
                            Audit::log('member.removed', null, ['email' => $record->email]);
                            $record->delete();
                            Notification::make()->title(__('Removed.'))->success()->send();
                        }),
                ]),
            ]);
    }

    private static function report(array $failed, string $ok): void
    {
        if ($failed === []) {
            Notification::make()->title($ok)->success()->send();

            return;
        }
        Notification::make()->title(__('Some servers could not be changed'))->body(implode("\n", $failed))->danger()->persistent()->send();
    }
}
