<?php

namespace App\Services;

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/** Members an admin makes (by importing a server user): they choose their own password by mail. */
class MemberAccounts
{
    public function create(string $name, string $email): User
    {
        $user = new User(['name' => $name, 'email' => $email, 'password' => Str::password(40)]);
        $user->save();
        $this->sendPasswordLink($user);

        return $user;
    }

    public function sendPasswordLink(User $user): void
    {
        $token = Password::broker()->createToken($user);
        $notification = new ResetPassword($token);
        $notification->url = Filament::getPanel('app')->getResetPasswordUrl($token, $user);
        $user->notify($notification);
    }
}
