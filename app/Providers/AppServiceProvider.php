<?php

namespace App\Providers;

use App\Http\Middleware\ApplySiteLocale;
use App\MediaServers\DriverFactory;
use App\Models\User;
use App\Services\LoginGuard;
use App\Services\SetupState;
use App\Services\SiteMail;
use App\Services\SiteSettings;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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

        $this->app->booted(function () {
            app(SiteMail::class)->apply();
            // Mails and messages from scheduled commands use the site's language too (web requests: ApplySiteLocale).
            if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
                $this->applySiteLocale();
            }
        });
        // The queue worker runs for long: each job gets the current settings, mail set-up and language.
        Queue::before(function () {
            app(SiteSettings::class)->refresh();
            app(SiteMail::class)->apply();
            $this->applySiteLocale();
        });

        // The API: per key presented (or none) and per IP, so guessing keys is slow too.
        RateLimiter::for('clubarr-api', fn (Request $request) => [
            Limit::perMinute(60)->by('key:'.hash('sha256', (string) $request->bearerToken())),
            Limit::perMinute(120)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('clubarr-api-write', fn (Request $request) => Limit::perHour(100)->by('key:'.hash('sha256', (string) $request->bearerToken())));

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
                LoginGuard::signedIn($event->user);
            }
        });
        // Only the email is used; what was typed as the password never leaves the event.
        Event::listen(Failed::class, fn (Failed $event) => LoginGuard::failed((string) ($event->credentials['email'] ?? ''), $event->user instanceof User ? $event->user : null));
    }

    private function applySiteLocale(): void
    {
        $locale = app(SiteSettings::class)->get('locale');
        if (isset(ApplySiteLocale::LOCALES[$locale])) {
            app()->setLocale($locale);
        }
    }
}
