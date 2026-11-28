<?php

namespace App\Filament\App\Concerns;

use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\MediaServerException;
use App\Models\User;
use App\Services\FamilyUsers;
use App\Services\SelfService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * On My page → Your access, when the admin allows it: extra users for the
 * family, pausing, and moving to another server. Everything is looked up
 * among the signed-in member's own records.
 */
trait MemberAccessActions
{
    abstract public function member(): User;

    public function addFamilyAction(): Action
    {
        return Action::make('addFamily')->label(__('Add a user for your family'))->icon('heroicon-m-user-plus')
            ->visible(fn () => FamilyUsers::max() > 0 && app(FamilyUsers::class)->remaining($this->member()) > 0 && app(FamilyUsers::class)->servers($this->member())->isNotEmpty())
            ->modalDescription(__('Their own user, with your libraries. It follows your access: paused and removed with yours. Streams count as yours.'))
            ->schema([
                Select::make('server')->label(__('Server'))->required()
                    ->options(fn () => app(FamilyUsers::class)->servers($this->member())->mapWithKeys(fn ($a) => [$a->media_server_id => $a->mediaServer->name])->all()),
                TextInput::make('label')->label(__('Who is it for?'))->required()->maxLength(60)->placeholder(__('e.g. Emma')),
                TextInput::make('username')->label(__('User name for watching'))->required()->minLength(3)->maxLength(32)->regex('/^[A-Za-z0-9._-]+$/'),
                TextInput::make('password')->label(__('Password'))->password()->revealable()->required()->rule(Password::min(10)->letters()->numbers()),
            ])
            ->action(function (array $data) {
                try {
                    $this->rateLimit(5, 3600, 'addFamily');
                    app(FamilyUsers::class)->add($this->member(), (int) $data['server'], (string) $data['label'], (string) $data['username'], (string) $data['password']);
                    Notification::make()->title(__('Made. They sign in with that user name and password.'))->success()->send();
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();
                } catch (MediaServerException $e) {
                    if ($e->userTaken) {
                        throw ValidationException::withMessages(['mountedActions.0.data.username' => $e->getMessage()]);
                    }
                    Notification::make()->title(__('The user could not be made right now. Please try again later.'))->danger()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public function removeFamilyAction(): Action
    {
        return Action::make('removeFamily')->label(__('Remove'))->color('danger')->link()->requiresConfirmation()
            ->modalDescription(__('The user is deleted on the server.'))
            ->action(function (array $arguments) {
                $extra = $this->member()->extraUsers()->with('mediaServer')->find((int) ($arguments['extra'] ?? 0));
                if ($extra === null) {
                    return;
                }
                try {
                    app(FamilyUsers::class)->remove($extra);
                    Notification::make()->title(__('Removed.'))->success()->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__('It could not be removed right now. Please try again later.'))->danger()->send();
                }
            });
    }

    public function pauseAction(): Action
    {
        return Action::make('pause')->label(__('Pause my access'))->icon('heroicon-m-pause-circle')->color('gray')
            ->visible(fn () => app(SelfService::class)->canPause($this->member()))
            ->modalDescription(__('Away for a while? Your users are paused, and the time is added to your end date when you come back.'))
            ->schema([TextInput::make('days')->label(__('For how many days'))->numeric()->required()->minValue(1)->maxValue(SelfService::maxPauseDays())->default(min(30, SelfService::maxPauseDays()))
                ->helperText(__('At most :days. You can come back earlier.', ['days' => SelfService::maxPauseDays()]))])
            ->action(function (array $data) {
                try {
                    app(SelfService::class)->pause($this->member(), (int) $data['days']);
                    Notification::make()->title(__('Paused. Come back here any time.'))->success()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }

    public function resumeAction(): Action
    {
        return Action::make('resume')->label(__('I\'m back: start my access again'))->icon('heroicon-m-play-circle')
            ->visible(fn () => $this->member()->self_paused_at !== null)
            ->action(function () {
                app(SelfService::class)->resume($this->member());
                Notification::make()->title(__('Welcome back!'))->success()->send();
            });
    }

    public function moveServerAction(): Action
    {
        return Action::make('moveServer')->label(__('Move to another server'))->icon('heroicon-m-arrows-right-left')->color('gray')
            ->visible(fn () => app(SelfService::class)->targetsFor($this->member())->isNotEmpty() && $this->member()->hasActiveAccess())
            ->modalDescription(__('You get a user on the new server, and the old one is removed. Your history on the old server does not move.'))
            ->schema([
                Select::make('from')->label(__('From'))->required()
                    ->options(fn () => $this->member()->serverAccounts()->with('mediaServer')->where('status', 'active')->get()->mapWithKeys(fn ($a) => [$a->id => $a->mediaServer->name])->all()),
                Select::make('to')->label(__('To'))->required()->live()
                    ->options(fn () => app(SelfService::class)->targetsFor($this->member())->pluck('name', 'id')->all()),
                TextInput::make('password')->label(__('Password for the new server'))->password()->revealable()->rule(Password::min(10)->letters()->numbers())
                    ->visible(fn (Get $get) => filled($get('to')) && app(SelfService::class)->targetsFor($this->member())->firstWhere('id', (int) $get('to'))?->type->can(CreatesUsers::class))
                    ->required(),
            ])
            ->action(function (array $data) {
                $from = $this->member()->serverAccounts()->with('mediaServer')->find((int) $data['from']);
                $to = app(SelfService::class)->targetsFor($this->member())->firstWhere('id', (int) $data['to']);
                try {
                    $this->rateLimit(3, 3600, 'moveServer');
                    if ($from === null || $to === null) {
                        throw new RuntimeException(__('You can\'t move there right now.'));
                    }
                    $new = app(SelfService::class)->move($this->member(), $from, $to, $data['password'] ?? null);
                    Notification::make()->title(__('Done. You are on :server now.', ['server' => $to->name]))->success()->send();
                    if ($new->claim_url) {
                        $this->redirect($new->claim_url);
                    }
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                } catch (Throwable $e) {
                    report($e);
                    Notification::make()->title(__('It could not be done right now. Please try again later.'))->danger()->send();
                }
            });
    }
}
