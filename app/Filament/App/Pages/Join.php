<?php

namespace App\Filament\App\Pages;

use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\SharesWithPlexAccounts;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Contracts\VerifiesLogins;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\MediaServerException;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\User;
use App\Models\WizardStep;
use App\Services\Applications;
use App\Services\ExistingUsers;
use App\Services\InvitationProblem;
use App\Services\NewOnServers;
use App\Services\PlexLogin;
use App\Services\Redeemer;
use App\Services\SiteSettings;
use App\Services\Waitlist;
use App\Services\WizardSteps;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\HtmlString;
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

    /** An invitation that ended or was used up: the visitor may ask for a new link for it. */
    #[Locked]
    public ?int $renewableId = null;

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
                $this->renewableId = $invitation !== null && $this->problem !== null && ! $invitation->is_template && $invitation->revoked_at === null && ! $invitation->awaiting_approval
                    && (($invitation->expires_at?->isPast() ?? false) || ($invitation->max_uses !== null && $invitation->uses >= $invitation->max_uses)) ? $invitation->id : null;
            }
        }
        $this->form->fill();
        // Back from signing in with Plex in another tab (phones): keep checking.
        $pin = session('join.plex.pin');
        $this->plexPending = $this->invitationId !== null && is_array($pin) && ($pin['invitation'] ?? null) === $this->invitationId;
        if (($invitation = $this->invitation()) !== null) {
            try {
                Redeemer::checkSeats($invitation, ($u = Filament::auth()->user()) instanceof User ? $u : null);
            } catch (InvitationProblem $e) {
                $this->fullNotice($e);
            }
        }
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
        $plex = $this->serversNeeding(SharesWithPlexAccounts::class) !== [];
        $signedIn = $this->plexIdentity();
        // Jellyfin/Emby servers of the invitation where the visitor may sign in with a user they already have.
        $linkable = app(SiteSettings::class)->get('link_existing') === '0' ? [] : array_map(fn (MediaServer $s) => $s->name, $this->serversNeeding(VerifiesLogins::class));

        $account = array_values(array_filter([
            $plex ? Section::make(__('Plex'))->compact()
                ->extraAttributes($this->plexPending ? ['wire:poll.3s' => 'plexCheck'] : [])
                ->schema([
                    Text::make($signedIn !== null ? __('Signed in with Plex as :name.', ['name' => $signedIn->username])
                        : ($this->plexPending ? __('Waiting for Plex… Approve Clubarr in the window that opened.') : __('Plex uses your own Plex account. Sign in with it, and the server is shared with you.'))),
                    $signedIn === null ? Actions::make([Action::make('plexStart')->label(__('Sign in with Plex'))->color('warning')
                        ->extraAttributes(['x-on:click' => "window.clubarrPlex = window.open('about:blank', 'clubarr-plex', 'width=600,height=720')"])
                        ->action(fn () => $this->plexStart())]) : null,
                ]) : null,
            $this->serversNeeding(UsesClaimLinks::class) !== [] ? Text::make(__('For Silo you choose your user on Silo\'s own page; the link is on the next page.')) : null,
            // Plex without the sign-in window: Plex sends the member an invitation to accept.
            $this->serversNeeding(SharesWithPlexAccounts::class) !== [] && $this->plexIdentity() === null
                ? TextInput::make('plex_account')->label(__('Or: your Plex email or user name'))->maxLength(190)->autocomplete('email')
                    ->regex('/^[^\s<>"\'\/\\\\]{2,190}$/')
                    ->helperText(__('Instead of signing in with Plex. Plex then sends you an invitation; accept it in the Plex app or in the mail from Plex.')) : null,
            $guest ? TextInput::make('name')->label(__('Your name'))->required()->maxLength(100) : null,
            $guest ? TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190)
                ->unique(User::class, 'email')->validationMessages(['unique' => __('An account with this email exists. Sign in first, then open the invitation link again.')]) : null,
            $linkable ? Toggle::make('existing')->label(__('I already have a user on :servers', ['servers' => implode(', ', $linkable)]))->live()
                ->helperText(__('Sign in with it below instead of making a new one. The password is only checked by the server, never kept.')) : null,
            $linkable ? TextInput::make('existing_username')->label(__('Your user name there'))->required()->maxLength(100)->visible(fn (Get $get) => (bool) $get('existing')) : null,
            $linkable ? TextInput::make('existing_password')->label(__('Your password there'))->password()->required()->maxLength(200)->visible(fn (Get $get) => (bool) $get('existing')) : null,
            $linkable !== [] ? Toggle::make('existing')->label(__('I already have a user on :servers', ['servers' => implode(', ', $linkable)]))->live()
                ->helperText(__('Sign in with it below instead of making a new one. The password is only checked by the server, never kept.')) : null,
            $linkable !== [] ? TextInput::make('existing_username')->label(__('Your user name there'))->required()->maxLength(100)->visible(fn (Get $get) => (bool) $get('existing')) : null,
            $linkable !== [] ? TextInput::make('existing_password')->label(__('Your password there'))->password()->required()->maxLength(200)->visible(fn (Get $get) => (bool) $get('existing')) : null,
            $needsUsername ? TextInput::make('username')->label(__('User name for watching'))->required()->minLength(3)->maxLength(32)
                ->hidden(fn (Get $get) => (bool) $get('existing'))
                ->hidden(fn (Get $get) => (bool) $get('existing') && count($linkable) === count($this->serversNeeding(CreatesUsers::class)))
                ->regex('/^[A-Za-z0-9._-]+$/')->helperText(__('Letters, digits, dot, dash and underscore. You sign in to the apps with it.')) : null,
            $guest || $needsUsername ? TextInput::make('password')->label(__('Password'))->password()->revealable()->required()
                ->hidden(fn (Get $get) => ! $guest && (bool) $get('existing'))
                ->rule(Password::min(10)->letters()->numbers())->same('password_confirmation')
                ->helperText($guest && $needsUsername ? __('For your account here and for watching.') : null) : null,
            $guest || $needsUsername ? TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required()->dehydrated(false)
                ->hidden(fn (Get $get) => ! $guest && (bool) $get('existing')) : null,
        ]));

        // What is waiting, the admin's own steps (the wizard, "before"), then the account. (After joining: Get started.)
        $before = app(WizardSteps::class)->forInvitation($invitation, 'before')
            ->map(fn (WizardStep $step) => Step::make($step->title)->icon('heroicon-o-information-circle')
                ->schema([View::make('filament.app.wizard-step')->viewData(['step' => $step])]))->all();

        return $schema->statePath('data')->components([
            Wizard::make([
                Step::make(__('Welcome'))->icon('heroicon-o-sparkles')->schema([
                    View::make('filament.app.join-welcome')->viewData(['welcome' => $this->welcome($invitation)]),
                ]),
                ...$before,
                Step::make(__('Your account'))->icon('heroicon-o-user-circle')->schema($account),
            ])->submitAction(new HtmlString(Blade::render('<x-filament::button type="submit" size="lg" icon="heroicon-m-check">'.e(Filament::auth()->check() ? __('Add to my account') : __('Create my account')).'</x-filament::button>'))),
        ]);
    }

    /**
     * What the welcome step shows: the greeting, who invites, the servers, how long, the site's text, posters.
     *
     * @return array<string, mixed>
     */
    #[Unexposed]
    public function welcome(Invitation $invitation): array
    {
        $creator = $invitation->by_member ? $invitation->creator : null;
        try {
            $posters = app(NewOnServers::class)->forInvitation($invitation);
        } catch (Throwable) {
            $posters = [];
        }

        return [
            'greeting' => $invitation->greeting,
            'from' => $creator?->name,
            'text' => app(SiteSettings::class)->get('invite_welcome'),
            'servers' => $invitation->mediaServers->filter(fn (MediaServer $s) => $s->is_enabled)->map(fn (MediaServer $s) => ['name' => $s->name, 'type' => $s->type->label()])->values()->all(),
            'days' => $invitation->access_days,
            'posters' => $posters,
        ];
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        $invitation = $this->invitation();
        if ($invitation === null) {
            return $schema->components(array_filter([
                $this->renewableId !== null ? Actions::make([$this->askNewLinkAction()])->fullWidth() : null,
                Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('findCode')
                    ->footer([Actions::make([Action::make('findCode')->label(__('Continue'))->submit('findCode')])->fullWidth()]),
            ]));
        }

        return $schema->components(array_filter([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('join'),
            ! Filament::auth()->check() ? Text::make(__('Already a member? Sign in first, then open the invitation link again.')) : null,
        ]));
    }

    /** "Ask for a new link": an admin approves, and a new link comes by mail. Same answer whatever happens. */
    public function askNewLinkAction(): Action
    {
        return Action::make('askNewLink')->label(__('Ask for a new link'))->icon('heroicon-m-arrow-path')->size('lg')
            ->visible(fn () => $this->renewableId !== null)
            ->modalDescription(__('We get your request, and a new link comes to your mail when it is approved.'))
            ->schema([
                TextInput::make('name')->label(__('Your name'))->required()->maxLength(100),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190),
                Textarea::make('message')->label(__('Anything to add? (optional)'))->rows(2)->maxLength(1000),
            ])
            ->action(function (array $data) {
                try {
                    $this->rateLimit(3, 3600, 'askNewLink');
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('Too many attempts. Try again later.'))->danger()->send();

                    return;
                }
                $old = Invitation::find($this->renewableId);
                if ($old !== null) {
                    app(Applications::class)->askRenewal($old, (string) $data['name'], (string) $data['email'], $data['message'] ?? null);
                }
                $this->renewableId = null;
                Notification::make()->title(__('Thanks! You get a mail when it is approved.'))->success()->persistent()->send();
            });
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
            $pin = app(PlexLogin::class)->start(request()->header('Referer'));
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
            $typed = trim((string) ($data['plex_account'] ?? ''));
            if ($identity === null && $typed === '') {
                Notification::make()->title(__('Sign in with Plex, or type your Plex email or user name.'))->warning()->send();

                return null;
            }
            // Typed: no token, so Plex asks the member to accept the share themselves.
            $plex = $identity !== null
                ? ['identity' => $identity, 'token' => (string) session('join.plex.done.token')]
                : ['identity' => new PlexIdentity('', $typed, str_contains($typed, '@') ? $typed : null), 'token' => ''];
        }
        $existing = Filament::auth()->user();
        // A user they already have on Jellyfin/Emby: checked with its own password, then linked instead of made.
        $linked = [];
        if (! empty($data['existing']) && app(SiteSettings::class)->get('link_existing') !== '0') {
            foreach ($this->serversNeeding(VerifiesLogins::class) as $server) {
                try {
                    $linked[$server->id] = app(ExistingUsers::class)->verify($server, (string) ($data['existing_username'] ?? ''), (string) ($data['existing_password'] ?? ''));
                } catch (\RuntimeException $e) {
                    throw ValidationException::withMessages(['data.existing_password' => $e->getMessage()]);
                }
            }
        }

        try {
            $user = app(Redeemer::class)->redeem(
                $invitation,
                $existing instanceof User ? $existing : null,
                $existing ? null : ['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']],
                (string) ($data['username'] ?? ''),
                $data['password'] ?? null,
                $plex,
                $linked,
            );
        } catch (InvitationProblem $e) {
            $this->fullNotice($e);

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

        // Get started shows the servers of this invitation only, not every server they have.
        return redirect()->to(GetStarted::getUrl(['invitation' => $invitation->id], panel: 'app'));
    }

    /** Tells the visitor why, with the way onto the waiting list when a server is full. */
    protected function fullNotice(InvitationProblem $e): void
    {
        $notice = Notification::make()->title($e->getMessage())->danger()->persistent();
        if ($e->full && Waitlist::enabled()) {
            $notice->body(__('Put yourself on the waiting list, and you get an invitation by mail when there is room.'))
                ->actions([Action::make('waitlist')->label(__('Join the waiting list'))->button()->url(route('filament.app.waitlist'))]);
        }
        $notice->send();
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
