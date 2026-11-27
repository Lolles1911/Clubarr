<?php

namespace App\Filament\App\Pages;

use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\SharesWithPlexAccounts;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\MediaServerException;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\User;
use App\Services\InvitationProblem;
use App\Services\PlexLogin;
use App\Services\Redeemer;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

/**
 * /join/{code}: redeem an invitation. A visitor makes their account here; a
 * signed-in member adds the invitation's servers to theirs. The invitation is
 * known by its ID in a locked property; the Plex sign-in is kept in the
 * session, never in the page.
 *
 * @property-read Schema $form
 */
class Join extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public ?int $invitationId = null;

    #[Locked]
    public ?string $problem = null;

    public bool $plexPending = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Unexposed]
    public function mount(?string $code = null): void
    {
        if ($code !== null) {
            $key = 'join-lookup:'.request()->ip();
            if (RateLimiter::tooManyAttempts($key, 20)) {
                $this->problem = __('Too many attempts. Try again in a minute.');
            } else {
                RateLimiter::hit($key, 60);
                $invitation = Invitation::findByCode($code);
                $this->problem = $invitation === null ? __('This invitation code is not valid.') : $invitation->problem();
                $this->invitationId = $this->problem === null ? $invitation?->id : null;
            }
        }
        $this->form->fill();
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Join');
    }

    #[Unexposed]
    public function getHeading(): string|Htmlable|null
    {
        return $this->invitation() !== null ? __('You\'re invited!') : __('Join with an invitation');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        $invitation = $this->invitation();
        if ($invitation === null) {
            return $this->problem;
        }
        $servers = $invitation->mediaServers->filter(fn (MediaServer $s) => $s->is_enabled)->map(fn (MediaServer $s) => $s->name.' ('.$s->type->label().')')->implode(', ');

        return $invitation->access_days !== null
            ? __('Access to :servers for :days days.', ['servers' => $servers, 'days' => $invitation->access_days])
            : __('Access to :servers.', ['servers' => $servers]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        $invitation = $this->invitation();
        if ($invitation === null) {
            return $schema->statePath('data')->components([
                TextInput::make('code')->label(__('Invitation code'))->required()->maxLength(80)->autocomplete('off'),
            ]);
        }
        $guest = ! Filament::auth()->check();
        $needsUsername = $this->serversNeeding(CreatesUsers::class) !== [];

        return $schema->statePath('data')->components(array_filter([
            $guest ? TextInput::make('name')->label(__('Your name'))->required()->maxLength(100) : null,
            $guest ? TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190)
                ->unique(User::class, 'email')->validationMessages(['unique' => __('An account with this email exists. Sign in first, then open the invitation link again.')]) : null,
            $needsUsername ? TextInput::make('username')->label(__('User name for watching'))->required()->minLength(3)->maxLength(32)
                ->regex('/^[A-Za-z0-9._-]+$/')->helperText(__('Letters, digits, dot, dash and underscore. You sign in to the apps with it.')) : null,
            $guest || $needsUsername ? TextInput::make('password')->label(__('Password'))->password()->revealable()->required()
                ->rule(Password::min(10)->letters()->numbers())->same('password_confirmation')
                ->helperText($guest && $needsUsername ? __('For your account here and for watching.') : null) : null,
            $guest || $needsUsername ? TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required()->dehydrated(false) : null,
        ]));
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $invitation = $this->invitation();
        if ($invitation === null) {
            return $schema->components([
                Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('findCode')
                    ->footer([Actions::make([Action::make('findCode')->label(__('Continue'))->submit('findCode')])->fullWidth()]),
            ]);
        }
        $plex = $this->serversNeeding(SharesWithPlexAccounts::class) !== [];
        $silo = $this->serversNeeding(UsesClaimLinks::class) !== [];
        $signedIn = $this->plexIdentity();

        return $schema->components(array_filter([
            $plex ? Section::make(__('Plex'))->compact()
                ->extraAttributes($this->plexPending ? ['wire:poll.3s' => 'plexCheck'] : [])
                ->schema([
                    Text::make($signedIn !== null ? __('Signed in with Plex as :name.', ['name' => $signedIn->username])
                        : ($this->plexPending ? __('Waiting for Plex… Approve Clubarr in the window that opened.') : __('Plex uses your own Plex account. Sign in with it, and the server is shared with you.'))),
                    $signedIn === null ? Actions::make([Action::make('plexStart')->label(__('Sign in with Plex'))->color('warning')
                        ->extraAttributes(['x-on:click' => "window.clubarrPlex = window.open('about:blank', 'clubarr-plex', 'width=600,height=720')"])
                        ->action(fn () => $this->plexStart())]) : null,
                ]) : null,
            $silo ? Text::make(__('For Silo you choose your user on Silo\'s own page; the link is on the next page.')) : null,
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('join')
                ->footer([Actions::make([Action::make('join')->label(Filament::auth()->check() ? __('Add to my account') : __('Create my account'))->submit('join')])->fullWidth()]),
            ! Filament::auth()->check() ? Text::make(__('Already a member? Sign in first, then open the invitation link again.')) : null,
        ]));
    }

    public function findCode(): mixed
    {
        $code = Invitation::normalize((string) ($this->form->getState()['code'] ?? ''));

        return redirect()->route('filament.app.join', ['code' => $code !== '' ? $code : null]);
    }

    protected function plexStart(): void
    {
        if ($this->invitation() === null) {
            return;
        }
        try {
            $this->rateLimit(10, method: 'plexStart');
            $pin = app(PlexLogin::class)->start();
        } catch (TooManyRequestsException) {
            $this->js('window.clubarrPlex && window.clubarrPlex.close()');
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

            return;
        } catch (Throwable $e) {
            report($e);
            $this->js('window.clubarrPlex && window.clubarrPlex.close()');
            Notification::make()->title(__('plex.tv could not be reached. Please try again.'))->danger()->send();

            return;
        }
        session()->put('join.plex.pin', ['id' => $pin['id'], 'code' => $pin['code'], 'invitation' => $this->invitationId]);
        $this->plexPending = true;
        $this->js('if (window.clubarrPlex) { window.clubarrPlex.location = '.json_encode($pin['url']).' } else { window.open('.json_encode($pin['url']).', "clubarr-plex", "width=600,height=720") }');
    }

    public function plexCheck(): void
    {
        $pin = session('join.plex.pin');
        if (! $this->plexPending || ! is_array($pin) || ($pin['invitation'] ?? null) !== $this->invitationId) {
            $this->plexPending = false;

            return;
        }
        try {
            $login = app(PlexLogin::class);
            $token = $login->token((string) $pin['id'], (string) $pin['code']);
            if ($token === null) {
                return; // not approved yet
            }
            $who = $login->identity($token);
        } catch (Throwable $e) {
            Log::info('Clubarr: Plex sign-in check failed: '.$e->getMessage());

            return;
        }
        session()->forget('join.plex.pin');
        session()->put('join.plex.done', ['invitation' => $this->invitationId, 'id' => $who->id, 'username' => $who->username, 'email' => $who->email, 'token' => $token]);
        $this->plexPending = false;
        $this->js('window.clubarrPlex && window.clubarrPlex.close()');
    }

    public function join(): mixed
    {
        $invitation = $this->invitation();
        if ($invitation === null) {
            return null;
        }
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->title(__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $e->secondsUntilAvailable]))->danger()->send();

            return null;
        }
        $data = $this->form->getState();
        $plex = null;
        if ($this->serversNeeding(SharesWithPlexAccounts::class) !== []) {
            $identity = $this->plexIdentity();
            if ($identity === null) {
                Notification::make()->title(__('Sign in with Plex first.'))->warning()->send();

                return null;
            }
            $plex = ['identity' => $identity, 'token' => (string) session('join.plex.done.token')];
        }
        $existing = Filament::auth()->user();

        try {
            $user = app(Redeemer::class)->redeem(
                $invitation,
                $existing instanceof User ? $existing : null,
                $existing ? null : ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
                (string) ($data['username'] ?? ''),
                $data['password'] ?? null,
                $plex,
            );
        } catch (InvitationProblem $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return null;
        } catch (MediaServerException $e) {
            if ($e->userTaken) {
                throw ValidationException::withMessages(['data.username' => $e->getMessage()]);
            }
            Log::warning('Clubarr: invitation '.$invitation->id.' could not be redeemed: '.$e->getMessage());
            Notification::make()->title(__('Your user could not be made on the media server right now. Please try again later, or tell the person who invited you.'))->danger()->send();

            return null;
        }

        session()->forget(['join.plex.done', 'join.plex.pin']);
        if (! $existing) {
            Filament::auth()->login($user);
            session()->regenerate();
        }
        Notification::make()->title(__('Welcome! Here is how to start watching.'))->success()->send();

        return redirect()->to(MyAccess::getUrl(panel: 'app'));
    }

    /** The invitation, while it can still be used. */
    protected function invitation(): ?Invitation
    {
        if ($this->invitationId === null) {
            return null;
        }
        $invitation = Invitation::with('mediaServers')->find($this->invitationId);

        return $invitation !== null && $invitation->isUsable() ? $invitation : null;
    }

    /** @return list<MediaServer> the invitation's servers of a kind that the visitor doesn't have yet */
    protected function serversNeeding(string $contract): array
    {
        $have = ($user = Filament::auth()->user()) instanceof User ? $user->serverAccounts()->pluck('media_server_id')->all() : [];

        return $this->invitation()?->mediaServers
            ->filter(fn (MediaServer $s) => $s->is_enabled && $s->type->can($contract) && ! in_array($s->id, $have, true))
            ->values()->all() ?? [];
    }

    protected function plexIdentity(): ?PlexIdentity
    {
        $done = session('join.plex.done');

        return is_array($done) && ($done['invitation'] ?? null) === $this->invitationId
            ? new PlexIdentity((string) $done['id'], (string) $done['username'], $done['email'] ?? null) : null;
    }
}
