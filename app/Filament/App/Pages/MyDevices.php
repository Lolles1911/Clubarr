<?php

namespace App\Filament\App\Pages;

use App\Models\User;
use App\Services\Watching;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Throwable;

/** What plays on the member's users now (with Stop), and their devices of the last 30 days. Needs Tracearr. */
class MyDevices extends Page
{
    use WithRateLimiting;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-device-phone-mobile';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.app.my-devices';

    public static function canAccess(): bool
    {
        return app(Watching::class)->enabled();
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('My devices');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('My devices');
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /** @return list<array<string, mixed>> */
    #[Unexposed]
    public function streams(): array
    {
        try {
            return app(Watching::class)->streamsOf($this->member());
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<array<string, mixed>> */
    #[Unexposed]
    public function devices(): array
    {
        return array_slice(app(Watching::class)->devicesOf($this->member()), 0, 30);
    }

    #[Unexposed]
    public function stopAction(): Action
    {
        return Action::make('stop')->label(__('Stop'))->color('danger')->size('sm')->requiresConfirmation()
            ->modalDescription(__('Something you don\'t recognise? Stop it, and choose a new password on My access.'))
            ->action(function (array $arguments) {
                try {
                    $this->rateLimit(10, 3600, 'stop');
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();

                    return;
                }
                try {
                    $stopped = app(Watching::class)->stop($this->member(), (string) ($arguments['id'] ?? ''));
                } catch (Throwable) {
                    $stopped = false;
                }
                $stopped ? Notification::make()->title(__('Stopped.'))->success()->send()
                    : Notification::make()->title(__('It could not be stopped right now. Please try again in a moment.'))->danger()->send();
            });
    }
}
