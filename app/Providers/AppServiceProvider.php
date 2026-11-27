<?php

namespace App\Providers;

use App\MediaServers\DriverFactory;
use App\Models\User;
use App\Services\SetupState;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SiteSettings::class);
        $this->app->singleton(SetupState::class);
        $this->app->singleton(DriverFactory::class);
    }

    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10)->letters()->numbers());

        $this->app->booted(fn () => app(SiteMail::class)->apply());

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
            }
        });
    }
}
