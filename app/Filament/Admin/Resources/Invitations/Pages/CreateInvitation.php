<?php

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use App\Services\Audit;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    private ?string $code = null;

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $invitation = new Invitation(collect($data)->only(['label', 'expires_at', 'max_uses', 'access_days', 'max_streams', 'request_limit'])->all());
            $invitation->created_by = Auth::id();
            $this->code = $invitation->assignCode();
            $invitation->save();
            $invitation->mediaServers()->sync(InvitationResource::pivotFor((array) $data['servers'], (array) ($data['libraries'] ?? [])));
            Audit::log('invitation.created', $invitation, ['label' => $invitation->label, 'servers' => (array) $data['servers']]);

            return $invitation;
        });
    }

    protected function getCreatedNotification(): ?Notification
    {
        InvitationResource::showCode((string) $this->code);
        InvitationResource::sendLink((string) $this->code, $this->data['send_to'] ?? null, $this->data['greeting'] ?? null);

        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
