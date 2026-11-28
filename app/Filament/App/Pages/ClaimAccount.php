<?php

namespace App\Filament\App\Pages;

use App\Enums\AccountStatus;
use App\Models\Claim;
use App\Models\ServerAccount;
use App\Models\User;
use App\Services\Audit;
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
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;

/**
 * /claim/{code}: a personal link from Import users. The person makes their
 * Clubarr account (or uses the one they are signed in with) and is linked
 * to their existing user on the server. One use; the claim ID is locked.
 *
 * @property-read Schema $form
 */
class ClaimAccount extends SimplePage
{
    use WithRateLimiting;

    #[Locked]
    public ?int $claimId = null;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Unexposed]
    public function mount(string $code): void
    {
        $key = 'claim-lookup:'.request()->ip();
        if (! RateLimiter::tooManyAttempts($key, 20)) {
            RateLimiter::hit($key, 60);
            $claim = Claim::findByCode($code);
            $this->claimId = $claim?->isUsable() ? $claim->id : null;
        }
        $this->form->fill(['name' => $this->currentClaim()?->remote_username]);
    }

    protected function currentClaim(): ?Claim
    {
        $claim = $this->claimId === null ? null : Claim::with('mediaServer')->find($this->claimId);

        return $claim?->isUsable() ? $claim : null;
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Your account');
    }

    #[Unexposed]
    public function getHeading(): string|Htmlable|null
    {
        return $this->currentClaim() !== null ? __('Welcome!') : __('This link can\'t be used');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        $claim = $this->currentClaim();

        return $claim === null ? __('It was used already, has expired, or is not valid. Ask the person who sent it for a new one.')
            : __('Make your account to see your :server user ":user", and more.', ['server' => $claim->mediaServer->name, 'user' => $claim->remote_username]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        if ($this->currentClaim() === null || Filament::auth()->check()) {
            return $schema->statePath('data')->components([]);
        }

        return $schema->statePath('data')->components([
            TextInput::make('name')->label(__('Your name'))->required()->maxLength(100),
            TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190)->unique(User::class, 'email')
                ->validationMessages(['unique' => __('An account with this email exists. Sign in first, then open the link again.')]),
            TextInput::make('password')->label(__('Password'))->password()->revealable()->required()
                ->rule(Password::min(10)->letters()->numbers())->same('password_confirmation')
                ->helperText(__('For your account here. Your password for watching stays the same.')),
            TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required()->dehydrated(false),
        ]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        if ($this->currentClaim() === null) {
            return $schema->components([]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('claim')
                ->footer([Actions::make([Action::make('claim')->label(Filament::auth()->check() ? __('Add to my account') : __('Create my account'))->submit('claim')])->fullWidth()]),
        ]);
    }

    public function claim(): mixed
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException) {
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

            return null;
        }
        $data = $this->form->getState();
        $existing = Filament::auth()->user();

        $user = DB::transaction(function () use ($data, $existing) {
            // Locked, so the link is used only once even when sent twice at the same moment.
            $claim = Claim::whereKey($this->claimId)->lockForUpdate()->first();
            if ($claim === null || ! $claim->isUsable() || ($existing instanceof User && $existing->serverAccounts()->where('media_server_id', $claim->media_server_id)->exists())) {
                return null;
            }
            $user = $existing instanceof User ? $existing : User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $claim->media_server_id, 'remote_id' => $claim->remote_id,
                'remote_username' => $claim->remote_username, 'status' => AccountStatus::Active]);
            $claim->forceFill(['used_at' => now()])->save();
            Audit::log('claim.used', $claim, ['remote_username' => $claim->remote_username], $user->id);

            return $user;
        });
        if ($user === null) {
            Notification::make()->title(__('This link can\'t be used'))->danger()->send();

            return null;
        }
        if (! $existing) {
            Filament::auth()->login($user);
            session()->regenerate();
        }

        return redirect()->to(MyAccess::getUrl(panel: 'app'));
    }
}
