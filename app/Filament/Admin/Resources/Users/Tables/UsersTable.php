<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\MemberGroup;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\GroupRules;
use App\Services\MemberAccounts;
use App\Services\MemberAdmin;
use App\Services\MemberRequests;
use App\Services\SiteSettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Throwable;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['serverAccounts.mediaServer', 'groups']))
            ->recordUrl(fn (User $record) => UserResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->weight('medium')->description(fn (User $record) => $record->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('servers')->label(__('Servers'))->badge()
                    ->state(fn (User $record) => $record->serverAccounts->map(fn ($a) => $a->mediaServer->name)->all())
                    ->color(fn (User $record, string $state) => $record->serverAccounts->first(fn ($a) => $a->mediaServer->name === $state)?->status->color() ?? 'gray'),
                TextColumn::make('groups.name')->label(__('Groups'))->badge()->visibleFrom('lg')
                    ->color(fn (User $record, string $state) => $record->groups->firstWhere('name', $state)->color ?? 'gray'),
                TextColumn::make('access_expires_at')->visibleFrom('sm')->label(__('Access ends'))->date('j M Y')->placeholder(__('No end'))->sortable()
                    ->color(fn (User $record) => $record->hasAccessExpired() ? 'danger' : null),
                TextColumn::make('role')->visibleFrom('md')->label(__('Role'))->badge()
                    ->state(fn (User $record) => $record->disabled_at ? __('Disabled') : ($record->is_admin ? __('Admin') : __('Member')))
                    ->color(fn (User $record) => $record->disabled_at ? 'danger' : ($record->is_admin ? 'primary' : 'gray')),
                TextColumn::make('last_login_at')->visibleFrom('md')->label(__('Last sign-in'))->since()->placeholder('—')->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_admin')->label(__('Admins')),
                SelectFilter::make('groups')->label(__('Group'))->relationship('groups', 'name')->multiple()->preload(),
                TernaryFilter::make('disabled')->label(__('Disabled'))->nullable()->attribute('disabled_at'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('bulkEnd')->label(__('Change access end'))->icon(Heroicon::OutlinedCalendarDays)
                        ->schema([
                            TextInput::make('days')->label(__('Add days'))->numeric()->minValue(1)->maxValue(3650)->helperText(__('From today, or from their end if later.')),
                            DateTimePicker::make('until')->label(__('Or set the end to'))->seconds(false),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $failed = [];
                            foreach (self::members($records) as $user) {
                                $until = filled($data['until'] ?? null) ? Carbon::parse($data['until'])
                                    : (filled($data['days'] ?? null) ? ($user->access_expires_at?->isFuture() ? $user->access_expires_at->copy() : now())->addDays((int) $data['days']) : null);
                                $failed = array_merge($failed, app(MemberAdmin::class)->setEnd($user, $until));
                            }
                            self::report($failed, __('Access changed.'));
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkGroup')->label(__('Add to a group'))->icon(Heroicon::OutlinedUserGroup)
                        ->schema([Select::make('group')->label(__('Group'))->required()->options(fn () => MemberGroup::orderBy('name')->pluck('name', 'id')->all())])
                        ->action(function (Collection $records, array $data) {
                            $group = MemberGroup::findOrFail($data['group']);
                            foreach (self::members($records) as $user) {
                                $user->groups()->syncWithoutDetaching([$group->id]);
                                app(GroupRules::class)->apply($user);
                            }
                            Audit::log('group.members_added', $group, ['count' => $records->count()]);
                            Notification::make()->title(__('Added to :group.', ['group' => $group->name]))->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkUngroup')->label(__('Remove from a group'))->icon(Heroicon::OutlinedUserMinus)
                        ->schema([Select::make('group')->label(__('Group'))->required()->options(fn () => MemberGroup::orderBy('name')->pluck('name', 'id')->all())])
                        ->action(function (Collection $records, array $data) {
                            foreach (self::members($records) as $user) {
                                $user->groups()->detach($data['group']);
                            }
                            Notification::make()->title(__('Removed from the group.'))->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkDisable')->label(__('Disable'))->icon(Heroicon::OutlinedPauseCircle)->color('warning')->requiresConfirmation()
                        ->action(fn (Collection $records) => self::report(self::members($records)->flatMap(fn (User $u) => app(MemberAdmin::class)->disable($u))->all(), __('Disabled.')))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkEnable')->label(__('Enable'))->icon(Heroicon::OutlinedPlayCircle)->color('success')
                        ->action(fn (Collection $records) => self::report(self::members($records)->flatMap(fn (User $u) => app(MemberAdmin::class)->enable($u))->all(), __('Enabled.')))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkMessage')->label(__('Send a message'))->icon(Heroicon::OutlinedEnvelope)
                        ->visible(fn () => app(SiteSettings::class)->get('mail_enabled') === '1')
                        ->schema([
                            TextInput::make('subject')->label(__('Title'))->required()->maxLength(120),
                            Textarea::make('body')->label(__('Message'))->required()->rows(5)->maxLength(4000),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $sent = 0;
                            foreach (self::members($records) as $user) {
                                MemberRequests::tell($user, (string) $data['subject'], (string) $data['body']);
                                $sent++;
                            }
                            Audit::log('message.mailed_selected', null, ['members' => $sent, 'title' => $data['subject']]);
                            Notification::make()->title(__('Sent to :count members.', ['count' => $sent]))->success()->send();
                        })->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkRemove')->label(__('Remove'))->icon(Heroicon::OutlinedTrash)->color('danger')->requiresConfirmation()
                        ->modalDescription(__('Their users are removed from the servers (Plex: the share), and their Clubarr accounts are deleted. This can\'t be undone.'))
                        ->action(fn (Collection $records) => self::report(self::members($records)->flatMap(fn (User $u) => app(MemberAdmin::class)->remove($u))->all(), __('Removed.')))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('extend')->label(__('Change access end'))->icon(Heroicon::OutlinedCalendarDays)
                        ->schema([DateTimePicker::make('until')->label(__('Access ends'))->seconds(false)->helperText(__('Empty: no end.'))])
                        ->fillForm(fn (User $record) => ['until' => $record->access_expires_at])
                        ->action(fn (User $record, array $data) => self::report(app(MemberAdmin::class)->setEnd($record, filled($data['until'] ?? null) ? Carbon::parse($data['until']) : null), __('Access changed.'))),
                    Action::make('disable')->label(__('Disable'))->icon(Heroicon::OutlinedPauseCircle)->color('warning')
                        ->visible(fn (User $record) => $record->disabled_at === null && ! $record->is(Auth::user()))
                        ->requiresConfirmation()->modalDescription(__('The member can\'t sign in, and their users on the servers are paused.'))
                        ->action(fn (User $record) => self::report(app(MemberAdmin::class)->disable($record), __('Disabled.'))),
                    Action::make('enable')->label(__('Enable'))->icon(Heroicon::OutlinedPlayCircle)->color('success')
                        ->visible(fn (User $record) => $record->disabled_at !== null)
                        ->action(fn (User $record) => self::report(app(MemberAdmin::class)->enable($record), __('Enabled.'))),
                    Action::make('passwordLink')->label(__('Send password link'))->icon(Heroicon::OutlinedEnvelope)
                        ->requiresConfirmation()->modalDescription(__('The member gets a mail with a link to choose a new password for Clubarr.'))
                        ->action(function (User $record) {
                            app(MemberAccounts::class)->sendPasswordLink($record);
                            Audit::log('member.password_link', $record);
                            Notification::make()->title(__('Sent.'))->success()->send();
                        }),
                    EditAction::make(),
                    Action::make('libraries')->label(__('Libraries'))->icon(Heroicon::OutlinedRectangleStack)
                        ->visible(fn (User $record) => $record->serverAccounts->isNotEmpty())
                        ->modalDescription(__('What they see on each server. All ticked = all libraries, also new ones.'))
                        ->fillForm(fn (User $record) => ['libs' => $record->serverAccounts->mapWithKeys(fn ($a) => [$a->id => $a->library_ids ?? $a->mediaServer->libraries()->pluck('external_id')->all()])->all()])
                        ->schema(fn (User $record) => $record->serverAccounts->map(fn ($a) => CheckboxList::make('libs.'.$a->id)->label($a->mediaServer->name)->columns(2)->bulkToggleable()
                            ->options($a->mediaServer->libraries()->orderBy('name')->pluck('name', 'external_id')->all()))->all())
                        ->action(function (User $record, array $data) {
                            $failed = [];
                            foreach ($record->serverAccounts()->with('mediaServer.libraries')->get() as $account) {
                                $all = $account->mediaServer->libraries->pluck('external_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
                                $chosen = collect($data['libs'][$account->id] ?? [])->map(fn ($id) => (string) $id)->intersect($all)->sort()->values()->all();
                                $new = $chosen === $all ? null : $chosen;
                                if ($new === ($account->library_ids === null ? null : collect($account->library_ids)->map(fn ($id) => (string) $id)->sort()->values()->all())) {
                                    continue; // unchanged
                                }
                                try {
                                    app(AccessManager::class)->changeLibraries($account, $new);
                                } catch (Throwable $e) {
                                    $failed[] = $account->mediaServer->name.': '.mb_substr($e->getMessage(), 0, 200);
                                }
                            }
                            self::report($failed, __('Libraries changed.'));
                        }),
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
                        ->action(fn (User $record) => self::report(app(MemberAdmin::class)->remove($record), __('Removed.'))),
                ]),
            ]);
    }

    /** The chosen members, never the admin doing it. @return \Illuminate\Support\Collection<int, User> */
    private static function members(Collection $records): \Illuminate\Support\Collection
    {
        return $records->filter(fn ($u) => $u instanceof User && ! $u->is(Auth::user()))->values()->toBase();
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
