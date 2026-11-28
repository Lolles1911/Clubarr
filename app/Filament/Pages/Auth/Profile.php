<?php

namespace App\Filament\Pages\Auth;

use App\Enums\AccountStatus;
use App\Filament\App\Concerns\MemberSettingsActions;
use App\Models\User;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\MemberHistory;
use App\Services\MemberInvites;
use App\Services\SiteSettings;
use App\Support\DeviceName;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Auth\SessionGuard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Filament's profile (name, email, password, two-factor), plus where the
 * person is signed in and "sign out everywhere else". A new email waits for
 * the link mailed to it, when mail is on; without mail it is changed at once.
 */
class Profile extends EditProfile
{
    use MemberSettingsActions;

    #[Unexposed]
    public static function getLabel(): string
    {
        return __('My page');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('My page');
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /**
     * My page: the account (name, email, password, two-factor, where you are
     * signed in), your numbers, and your settings (weekly mail, Discord,
     * deleting the account), each on a tab.
     */
    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $member = $this->member();

        return $schema->components([
            Tabs::make('my-page')->persistTabInQueryString()->tabs([
                Tab::make(__('Account'))->key('account')->icon('heroicon-o-user-circle')->schema([
                    $this->getFormContentComponent(),
                    ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                    Section::make(__('Where you are signed in'))
                        ->description(__('Browsers signed in to your account here. Apps on TVs and phones sign in to the media servers, not here.'))
                        ->compact()->secondary()
                        ->schema([
                            View::make('filament.app.signed-in-devices')->viewData(['devices' => $this->devices()]),
                            Actions::make([$this->signOutOthersAction()]),
                        ]),
                ]),
                Tab::make(__('My numbers'))->key('numbers')->icon('heroicon-o-chart-bar')
                    ->visible(fn () => ! $member->is_admin || $member->serverAccounts()->exists())
                    ->schema([View::make('filament.app.my-page-numbers')->viewData(['stats' => $this->numbers(), 'status' => $this->accountStatus()])]),
                Tab::make(__('Settings'))->key('settings')->icon('heroicon-o-cog-6-tooth')->schema([
                    View::make('filament.app.my-page-settings')->viewData(['member' => $member, 'discord' => app(DiscordSync::class)->enabled()]),
                    Actions::make([$this->digestAction(), $this->deleteAccountAction()]),
                ]),
            ]),
        ]);
    }

    /** @return array{hours7: float, hours30: float, plays30: int, top: ?string}|null */
    #[Unexposed]
    public function numbers(): ?array
    {
        try {
            return app(MemberHistory::class)->stats($this->member());
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{ends: ?string, groups: list<string>, servers: int, invites: ?int, requests: ?string} */
    #[Unexposed]
    public function accountStatus(): array
    {
        $member = $this->member();

        return [
            'ends' => $member->access_expires_at?->translatedFormat(__('j F Y')),
            'groups' => $member->groups()->pluck('name')->all(),
            'servers' => $member->serverAccounts()->where('status', AccountStatus::Active)->count(),
            'invites' => MemberInvites::allowed($member) ? MemberInvites::remaining($member) : null,
            'requests' => ! $member->seerr_access ? null : ($member->request_limit === null ? __('No limit') : __(':count a week', ['count' => $member->request_limit])),
        ];
    }

    /** @return list<array{name: string, ip: ?string, active: Carbon, current: bool}> */
    #[Unexposed]
    public function devices(): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', Filament::auth()->id())
            ->orderByDesc('last_activity')->limit(20)->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row) => [
                'name' => DeviceName::from($row->user_agent),
                'ip' => $row->ip_address,
                'active' => Carbon::createFromTimestamp((int) $row->last_activity),
                'current' => hash_equals((string) session()->getId(), (string) $row->id),
            ])->values()->all();
    }

    #[Unexposed]
    public function signOutOthersAction(): Action
    {
        return Action::make('signOutOthers')->label(__('Sign out everywhere else'))->icon('heroicon-m-arrow-right-start-on-rectangle')->color('gray')
            ->modalDescription(__('Every other browser signed in to your account is signed out. Use it if you lost a device or think someone else knows your password (then change it too).'))
            ->schema([TextInput::make('password')->label(__('Your password'))->password()->required()->currentPassword()])
            ->action(function (array $data) {
                /** @var SessionGuard $guard */
                $guard = Filament::auth();
                $guard->logoutOtherDevices((string) $data['password']);
                $user = $guard->user();
                // This browser stays signed in with the new password hash.
                session()->put('password_hash_'.Filament::getAuthGuard(), $user?->getAuthPassword());
                if (config('session.driver') === 'database') {
                    DB::table((string) config('session.table', 'sessions'))->where('user_id', $user?->getAuthIdentifier())
                        ->where('id', '!=', session()->getId())->delete();
                }
                Audit::log('member.signed_out_others', $user instanceof Model ? $user : null);
                Notification::make()->title(__('Signed out everywhere else.'))->success()->send();
            });
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, #[SensitiveParameter] array $data): Model
    {
        if (array_key_exists('email', $data) && $data['email'] !== $record->getAttributeValue('email')) {
            Audit::log('member.email_change', $record, [], (int) $record->getKey());
        }
        if (app(SiteSettings::class)->get('mail_enabled') === '1') {
            return parent::handleRecordUpdate($record, $data);
        }
        $record->update($data);

        return $record;
    }
}
