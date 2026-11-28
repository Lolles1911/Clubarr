<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Audit;
use App\Support\AdminRole;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Admins are made here; members only by redeeming an invitation (or an import). */
class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
        $user->is_admin = (bool) ($data['is_admin'] ?? false);
        $user->admin_role = $user->is_admin && ($data['admin_role'] ?? '') === AdminRole::MODERATOR ? AdminRole::MODERATOR : null;
        $user->email_verified_at = now();
        $user->save();
        Audit::log($user->is_admin ? 'admin.created' : 'member.created', $user, ['email' => $user->email]);

        return $user;
    }
}
