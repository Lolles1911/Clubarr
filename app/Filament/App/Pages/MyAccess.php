<?php

namespace App\Filament\App\Pages;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\SiteSettings;
use App\Services\Watching;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
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

    /** @return array{hours7: float, hours30: float, plays30: int}|null */
    #[Unexposed]
    public function stats(): ?array
    {
        $watching = app(Watching::class);

        return $watching->enabled() ? $watching->statsOf($this->member()) : null;
    }

    /** @return array{continue: list<array>, popular: list<array>, new: list<array>} */
    #[Unexposed]
    public function posterRows(): array
    {
        try {
            return app(Watching::class)->posterRows($this->member());
        } catch (Throwable) {
            return ['continue' => [], 'popular' => [], 'new' => []];
        }
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

    /** The account named in the action's arguments, only if it is the member's own. */
    protected function ownAccount(array $arguments): ?ServerAccount
    {
        return $this->member()->serverAccounts()->with('mediaServer')->find((int) ($arguments['account'] ?? 0));
    }
}
