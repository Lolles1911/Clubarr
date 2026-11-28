<?php

namespace App\Services;

use App\Models\KnownDevice;
use App\Models\User;
use App\Notifications\NewSignIn;
use App\Support\DeviceName;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign-in security on top of Filament's limit per IP: failed sign-ins are
 * logged, an account (by the email typed) is locked for a while after too
 * many, and a sign-in from a device not seen before can be mailed to the
 * person. The lock counts any email typed, so it tells nothing about which
 * accounts exist.
 */
class LoginGuard
{
    public const MAX_FAILURES = 10;

    public const LOCK_MINUTES = 15;

    public const COOKIE = 'clubarr_device';

    /** Devices remembered per person; the oldest go first. */
    private const KEEP_DEVICES = 20;

    /** Seconds until the account behind this email may try again, or null. */
    public static function lockedFor(string $email): ?int
    {
        $key = self::key($email);

        return RateLimiter::tooManyAttempts($key, self::MAX_FAILURES) ? max(1, RateLimiter::availableIn($key)) : null;
    }

    /** A wrong password (or an unknown email). Never logs what was typed. */
    public static function failed(string $email, ?User $user): void
    {
        $key = self::key($email);
        $failures = RateLimiter::hit($key, self::LOCK_MINUTES * 60);
        Audit::log('auth.failed', $user, [], null);
        if ($failures === self::MAX_FAILURES) {
            Audit::log('auth.locked', $user, ['failures' => $failures, 'minutes' => self::LOCK_MINUTES], null);
        }
    }

    public static function succeeded(string $email): void
    {
        RateLimiter::clear(self::key($email));
    }

    /**
     * Called on every sign-in: remembers the device (a random cookie, kept
     * as a hash) and, for a device not seen before, mails the person, unless
     * it is their first device ever (just joined).
     */
    public static function signedIn(User $user): void
    {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return;
        }
        try {
            $token = request()->cookie(self::COOKIE);
            $token = is_string($token) && preg_match('/^[A-Za-z0-9]{40}$/', $token) ? $token : null;
            if ($token !== null) {
                $known = $user->knownDevices()->where('token_hash', hash('sha256', $token))->first();
                if ($known !== null) {
                    $known->forceFill(['last_used_at' => now()])->save();

                    return;
                }
            }
            $first = ! $user->knownDevices()->exists();
            if ($token === null) {
                $token = Str::random(40);
                Cookie::queue(Cookie::make(self::COOKIE, $token, 60 * 24 * 400, httpOnly: true, sameSite: 'lax'));
            }
            $name = mb_substr(DeviceName::from(request()->userAgent()), 0, 100);
            $user->knownDevices()->create(['token_hash' => hash('sha256', $token), 'name' => $name, 'last_used_at' => now()]);
            $user->knownDevices()->orderByDesc('last_used_at')->orderByDesc('id')->skip(self::KEEP_DEVICES)->take(100)->get()
                ->each(fn (KnownDevice $old) => $old->delete());
            if ($first) {
                return;
            }
            Audit::log('auth.new_device', $user, ['device' => $name], $user->id);
            $settings = app(SiteSettings::class);
            if ($settings->get('login_alerts') !== '0' && $settings->get('mail_enabled') === '1') {
                $user->notify(new NewSignIn($name, (string) request()->ip(), now()));
            }
        } catch (Throwable $e) {
            report($e); // never in the way of signing in
        }
    }

    private static function key(string $email): string
    {
        return 'clubarr:login:'.hash('sha256', mb_strtolower(trim($email)));
    }
}
