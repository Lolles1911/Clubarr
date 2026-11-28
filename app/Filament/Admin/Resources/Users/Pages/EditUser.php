<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Services\Audit;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $record->fill(collect($data)->only(['name', 'email'])->all());
        $record->access_expires_at = $data['access_expires_at'] ?? null;
        $record->max_streams = filled($data['max_streams'] ?? null) ? (int) $data['max_streams'] : null;
        $record->request_limit = filled($data['request_limit'] ?? null) ? (int) $data['request_limit'] : null;
        $record->seerr_access = (bool) ($data['seerr_access'] ?? false);
        if ($record->isDirty('access_expires_at')) {
            $record->expiry_warned_at = null;
        }
        // You can't take away your own admin rights (and lock yourself out).
        if (array_key_exists('is_admin', $data) && ! $record->is(Auth::user())) {
            $record->is_admin = (bool) $data['is_admin'];
        }
        $changes = array_keys($record->getDirty());
        $record->save();
        Audit::log('member.updated', $record, ['changed' => $changes]);

        return $record;
    }
}
