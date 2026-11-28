<?php

namespace App\Filament\Pages\Auth;

use App\Services\LoginGuard;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Validation\ValidationException;

/** Filament's sign-in, plus a lock per account after too many failed sign-ins (see LoginGuard). */
class Login extends FilamentLogin
{
    public function authenticate(): ?LoginResponse
    {
        $email = (string) ($this->data['email'] ?? '');
        if (! $this->isSubmittingMultiFactorChallenge() && ($seconds = LoginGuard::lockedFor($email)) !== null) {
            throw ValidationException::withMessages(['data.email' => __('Too many failed sign-ins for this account. Please try again in :minutes minutes.', ['minutes' => (int) ceil($seconds / 60)])]);
        }
        $response = parent::authenticate();
        if ($response !== null) {
            LoginGuard::succeeded($email);
        }

        return $response;
    }
}
