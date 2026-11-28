<?php

namespace App\Filament\App\Pages;

use App\MediaServers\MediaServerException;
use App\Models\User;
use App\Services\Requests as RequestService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/** Requests: search Seerr, request a movie or show (within the weekly limit), see your requests. */
class Requests extends Page
{
    use WithRateLimiting;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.app.requests';

    #[Url]
    public string $search = '';

    /** @var list<array<string, mixed>> set only by find() */
    #[Locked]
    public array $results = [];

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return app(RequestService::class)->enabled() && $user instanceof User && $user->seerr_access;
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Requests');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Requests');
    }

    #[Unexposed]
    public function mount(): void
    {
        if ($this->search !== '') {
            $this->find();
        }
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    public function find(): void
    {
        try {
            $this->rateLimit(30, 60, 'find');
            $this->results = app(RequestService::class)->search($this->member(), $this->search);
        } catch (TooManyRequestsException) {
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();
        } catch (MediaServerException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        } catch (Throwable $e) {
            report($e);
            Notification::make()->title(__('Search is not available right now. Please try again later.'))->danger()->send();
        }
    }

    /** @return list<array<string, mixed>> */
    #[Unexposed]
    public function mine(): array
    {
        try {
            return app(RequestService::class)->recent($this->member());
        } catch (Throwable) {
            return [];
        }
    }

    #[Unexposed]
    public function remaining(): ?int
    {
        return app(RequestService::class)->remaining($this->member());
    }

    #[Unexposed]
    public function requestAction(): Action
    {
        return Action::make('request')->label(__('Request'))->size('sm')
            ->action(function (array $arguments) {
                try {
                    $this->rateLimit(20, 3600, 'request');
                    app(RequestService::class)->request($this->member(), (string) ($arguments['type'] ?? ''), (int) ($arguments['tmdb'] ?? 0));
                    Notification::make()->title(__('Requested. You can follow it below.'))->success()->send();
                    $this->find();
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('That was a few times now. Please try again in an hour.'))->danger()->send();
                } catch (MediaServerException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();
                }
            });
    }
}
