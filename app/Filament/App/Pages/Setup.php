<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Services\Audit;
use App\Services\SetupState;
use App\Services\SiteSettings;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * The first start: the setup token from the log, the site's name and the
 * first admin. Gone (404) once an admin exists.
 *
 * @property-read Schema $form
 */
class Setup extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Unexposed]
    public function mount(): void
    {
        abort_if(app(SetupState::class)->isDone(), 404);
        app(SetupState::class)->token(); // made now, so it is in the log
        $this->form->fill(['site_name' => 'Clubarr', 'require_2fa' => true]);
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Set up Clubarr');
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Text::make(__('Welcome! Clubarr printed a setup token in its log when it started (docker logs clubarr). It proves this is your installation.')),
            TextInput::make('token')->label(__('Setup token'))->required()->password()->revealable()->autocomplete('off'),
            TextInput::make('site_name')->label(__('Name of your site'))->required()->maxLength(60),
            TextInput::make('name')->label(__('Your name'))->required()->maxLength(100),
            TextInput::make('email')->label(__('Your email'))->email()->required()->maxLength(190),
            TextInput::make('password')->label(__('Password'))->password()->revealable()->required()
                ->rule(Password::min(12)->letters()->numbers())->same('password_confirmation'),
            TextInput::make('password_confirmation')->label(__('Password again'))->password()->revealable()->required()->dehydrated(false),
            Toggle::make('require_2fa')->label(__('Require two-factor sign-in for admins (recommended)'))->default(true)
                ->helperText(__('With an authenticator app. You can change it later under Settings.')),
        ]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('finish')
                ->footer([Actions::make([Action::make('finish')->label(__('Create my admin account'))->submit('finish')])->fullWidth()]),
        ]);
    }

    public function finish(): mixed
    {
        $state = app(SetupState::class);
        abort_if($state->isDone(), 404);
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $e) {
            Notification::make()->title(__('Too many attempts. Try again in :seconds seconds.', ['seconds' => $e->secondsUntilAvailable]))->danger()->send();

            return null;
        }

        $data = $this->form->getState();
        if (! $state->check((string) $data['token'])) {
            throw ValidationException::withMessages(['data.token' => __('That is not the setup token from the log.')]);
        }

        $user = DB::transaction(function () use ($data) {
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->is_admin = true;
            $user->email_verified_at = now();
            $user->save();

            return $user;
        });
        app(SiteSettings::class)->set(['site_name' => $data['site_name'], 'admin_2fa_required' => ! empty($data['require_2fa']) ? '1' : '0']);
        $state->finish();
        Audit::log('setup.finished', $user, [], $user->id);

        Filament::getPanel('admin')->auth()->login($user);
        session()->regenerate();

        return redirect()->to(Filament::getPanel('admin')->getUrl());
    }
}
