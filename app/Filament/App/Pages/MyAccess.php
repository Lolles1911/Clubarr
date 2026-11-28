<?php

namespace App\Filament\App\Pages;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Contracts\VerifiesLogins;
use App\Models\Announcement;
use App\Models\Challenge;
use App\Models\MediaServer;
use App\Models\MovieNight;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\ComingSoon;
use App\Services\DiscordSync;
use App\Services\ExistingUsers;
use App\Services\MemberHistory;
use App\Services\MemberRequests;
use App\Services\NewOnServers;
use App\Services\SiteSettings;
use App\Services\Watching;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;
use Throwable;

/**
 * The member's start page: their servers (open, user name, status), when
 * access ends, and a new password for watching. Every action looks the
 * account up among the signed-in member's own.
 */
class MyAccess extends Dashboard
{
    use WithRateLimiting;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-play-circle';

    protected string $view = 'filament.app.my-access';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('My access');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('My access');
    }

    #[Unexposed]
    public function getWidgets(): array
    {
        return [];
    }

    /** @return Collection<int, ServerAccount> */
    #[Unexposed]
    public function accounts(): Collection
    {
        $user = $this->member();
        // Silo links and Plex shares taken since the last look (at most once a minute).
        if (Cache::add('clubarr:pending-check:'.$user->id, 1, 60)) {
            foreach ($user->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Pending)->get() as $account) {
                app(AccessManager::class)->refreshPending($account);
            }
        }

        return $user->serverAccounts()->with('mediaServer.libraries')->orderBy('id')->get();
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /** @return array{hours7: float, hours30: float, plays30: int, top: ?string}|null from Tracearr/Tautulli, or Jellyfin/Emby */
    #[Unexposed]
    public function stats(): ?array
    {
        try {
            return app(MemberHistory::class)->stats($this->member());
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{continue: list<array>, popular: list<array>, new: list<array>} */
    #[Unexposed]
    public function posterRows(): array
    {
        try {
            $rows = app(Watching::class)->posterRows($this->member());
            if ($rows['continue'] === [] && ! app(Watching::class)->enabled()) {
                $rows['continue'] = app(MemberHistory::class)->resume($this->member());
            }

            return $rows;
        } catch (Throwable) {
            return ['continue' => [], 'popular' => [], 'new' => []];
        }
    }

    /** @return list<array{key: string, title: string, year: int|null, server: string}> */
    #[Unexposed]
    public function newOnServers(): array
    {
        try {
            return app(NewOnServers::class)->forMember($this->member());
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array{title: string, detail: string, date: string, tmdb: ?string}> */
    #[Unexposed]
    public function comingSoon(): array
    {
        return $this->member()->hasActiveAccess() ? app(ComingSoon::class)->items() : [];
    }

    /** Messages from the admins shown as a banner, for this member's servers. */
    #[Unexposed]
    public function banners(): Collection
    {
        return Announcement::bannersFor($this->member());
    }

    #[Unexposed]
    public function discordEnabled(): bool
    {
        return app(DiscordSync::class)->enabled();
    }

    #[Unexposed]
    public function helpText(): ?string
    {
        return app(SiteSettings::class)->get('help_text');
    }

    #[Unexposed]
    public function canChangePassword(ServerAccount $account): bool
    {
        return $account->status === AccountStatus::Active && $account->remote_id !== null
            && ($account->mediaServer->type->can(CreatesUsers::class) || $account->mediaServer->type->can(UsesClaimLinks::class));
    }

    #[Unexposed]
    public function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label(__('New password'))
            ->link()
            ->modalHeading(fn (array $arguments) => __('New password for :server', ['server' => $this->ownAccount($arguments)?->mediaServer->name]))
            ->modalDescription(__('Apps and TVs signed in with the old password may ask you to sign in again.'))
            ->schema(fn (array $arguments) => $this->ownAccount($arguments)?->mediaServer->type->can(CreatesUsers::class) ? [
                TextInput::make('password')->label(__('New password'))->password()->revealable()->required()
                    ->rule(Password::min(10)->letters()->numbers())->same('password_confirmation'),
                TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required(),
            ] : [])
            ->modalSubmitActionLabel(fn (array $arguments) => $this->ownAccount($arguments)?->mediaServer->type->can(UsesClaimLinks::class) ? __('Open the password page') : __('Save'))
            ->action(function (array $arguments, array $data) {
                $account = $this->ownAccount($arguments);
                if ($account === null || ! $this->canChangePassword($account)) {
                    return;
                }
                try {
                    $this->rateLimit(5, 3600, 'changePassword');
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();

                    return;
                }
                $driver = $account->mediaServer->driver();
                try {
                    if ($driver instanceof CreatesUsers) {
                        $driver->setPassword((string) $account->remote_id, (string) $data['password']);
                        Audit::log('account.password_changed', $account, ['server' => $account->mediaServer->name]);
                        Notification::make()->title(__('Your password for :server is changed.', ['server' => $account->mediaServer->name]))->success()->send();
                    } elseif ($driver instanceof UsesClaimLinks) {
                        $url = $driver->passwordResetLink((string) $account->remote_id);
                        Audit::log('account.password_link', $account, ['server' => $account->mediaServer->name]);
                        $this->redirect($url);
                    }
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__('The password could not be changed right now. Please try again later.'))->danger()->send();
                }
            });
    }

    /** Jellyfin/Emby servers where the member has no user yet, and could link one they have (when the admin allows it). */
    #[Unexposed]
    public function linkableServers(): \Illuminate\Support\Collection
    {
        if (app(SiteSettings::class)->get('link_existing') === '0') {
            return collect();
        }

        return MediaServer::where('is_enabled', true)->whereNotIn('id', $this->member()->serverAccounts()->pluck('media_server_id'))->orderBy('name')->get()
            ->filter(fn (MediaServer $s) => $s->type->can(VerifiesLogins::class))->values();
    }

    public function linkExistingAction(): Action
    {
        return Action::make('linkExisting')->label(__('I already have a user on a server'))->icon('heroicon-m-link')->color('gray')->link()
            ->visible(fn () => $this->linkableServers()->isNotEmpty())
            ->modalDescription(__('Sign in with that user\'s name and password, and it is linked to your account here. The password is only checked by the server, never kept.'))
            ->schema([
                Select::make('server')->label(__('Server'))->required()->options(fn () => $this->linkableServers()->pluck('name', 'id')->all()),
                TextInput::make('username')->label(__('User name on the server'))->required()->maxLength(100),
                TextInput::make('password')->label(__('Password on the server'))->password()->required()->maxLength(200),
            ])
            ->action(function (array $data) {
                try {
                    $this->rateLimit(5, 3600, 'linkExisting');
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();

                    return;
                }
                $server = $this->linkableServers()->firstWhere('id', (int) $data['server']);
                try {
                    if ($server === null) {
                        throw new \RuntimeException(__('Choose a server.'));
                    }
                    $existing = app(ExistingUsers::class);
                    $existing->link($this->member(), $server, $existing->verify($server, (string) $data['username'], (string) $data['password']), 'member');
                    Notification::make()->title(__('Linked. :server is on your page now.', ['server' => $server->name]))->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__(':server did not answer. Please try again later.', ['server' => $server?->name]))->danger()->send();
                }
            });
    }

    /** @return Collection<int, MovieNight> */
    #[Unexposed]
    public function movieNights(): Collection
    {
        return MovieNight::upcomingFor($this->member());
    }

    /** @return list<array{challenge: Challenge, progress: float}> running challenges and how far the member is */
    #[Unexposed]
    public function challenges(): array
    {
        $running = Challenge::running();
        if ($running->isEmpty()) {
            return [];
        }
        try {
            $plays = app(MemberHistory::class)->yearPlays($this->member());
        } catch (Throwable) {
            $plays = [];
        }

        return $running->map(fn (Challenge $c) => ['challenge' => $c, 'progress' => $c->progress($plays)])->all();
    }

    public function comingAction(): Action
    {
        return Action::make('coming')->link()->size('sm')
            ->label(fn (array $arguments) => $this->member()->movieNights()->whereKey((int) ($arguments['night'] ?? 0))->exists() ? __('I can\'t come after all') : __('I\'m coming'))
            ->action(function (array $arguments) {
                $night = $this->movieNights()->firstWhere('id', (int) ($arguments['night'] ?? 0));
                if ($night !== null) {
                    $this->member()->movieNights()->toggle([$night->id]);
                }
            });
    }

    public function askForTimeAction(): Action
    {
        return Action::make('askForTime')->label(__('Ask for more time'))->icon('heroicon-m-clock')->color('gray')
            ->visible(fn () => MemberRequests::canAskForTime($this->member()))
            ->schema([Textarea::make('note')->label(__('A few words (optional)'))->rows(2)->maxLength(1000)])
            ->action(function (array $data) {
                try {
                    app(MemberRequests::class)->askForTime($this->member(), $data['note'] ?? null);
                    Notification::make()->title(__('Asked. You get a mail when it is decided.'))->success()->send();
                } catch (\RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->warning()->send();
                }
            });
    }

    /** The account named in the action's arguments, only if it is the member's own. */
    protected function ownAccount(array $arguments): ?ServerAccount
    {
        return $this->member()->serverAccounts()->with('mediaServer')->find((int) ($arguments['account'] ?? 0));
    }
}
