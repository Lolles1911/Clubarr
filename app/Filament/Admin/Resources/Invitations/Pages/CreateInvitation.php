<?php

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationMaker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateInvitation extends CreateRecord
{
    protected static string $resource = InvitationResource::class;

    private ?string $code = null;

    protected function handleRecordCreation(array $data): Model
    {
        $template = ! empty($data['is_template']);
        if ($template) {
            $data['expires_at'] = null;
        }
        $user = Auth::user();
        [$invitation, $this->code] = app(InvitationMaker::class)->create(
            $data,
            InvitationResource::pivotFor((array) $data['servers'], (array) ($data['libraries'] ?? [])),
            $user instanceof User ? $user : null,
            template: $template,
        );

        return $invitation;
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var Invitation $invitation */
        $invitation = $this->getRecord();
        if ($invitation->is_template) {
            return Notification::make()->title(__('Template saved.'))->success();
        }
        InvitationResource::showCode((string) $this->code);
        InvitationResource::sendLink($invitation, (string) $this->code, $this->data['send_to'] ?? null, $this->data['send_discord'] ?? null);

        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
