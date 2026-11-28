<?php

namespace App\Http\Controllers;

use App\Services\SingleSignOn;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Throwable;

/** /sso/redirect and /sso/callback: see SingleSignOn. */
class SingleSignOnController
{
    public function redirect(SingleSignOn $sso): RedirectResponse
    {
        abort_unless(SingleSignOn::enabled(), 404);
        try {
            return redirect()->away($sso->start());
        } catch (Throwable $e) {
            return $this->fail($e instanceof RuntimeException ? $e->getMessage() : __('The provider could not be reached.'));
        }
    }

    public function callback(Request $request, SingleSignOn $sso): RedirectResponse
    {
        abort_unless(SingleSignOn::enabled(), 404);
        $key = 'sso:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->fail(__('Too many attempts. Try again in a minute.'));
        }
        RateLimiter::hit($key, 60);
        if ($request->query('error')) {
            return $this->fail(__('The sign-in was cancelled.'));
        }
        try {
            $user = $sso->finish($request->query('state'), $request->query('code'));
        } catch (RuntimeException $e) {
            return $this->fail($e->getMessage());
        }
        Filament::auth()->login($user);
        $request->session()->regenerate();
        // The provider did the second factor (set it up there): Clubarr's own is not asked for this session.
        $request->session()->put('clubarr.sso', true);

        return redirect()->to($user->is_admin ? url('/admin') : url('/'));
    }

    private function fail(string $message): RedirectResponse
    {
        Notification::make()->title($message)->danger()->persistent()->send();

        return redirect()->to(Filament::getPanel('app')->getLoginUrl());
    }
}
