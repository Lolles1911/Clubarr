<?php

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use App\Services\Audit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class EditInvitation extends EditRecord
{
    protected static string $resource = InvitationResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Invitation $invitation */
        $invitation = $this->getRecord();
        $data['servers'] = $invitation->mediaServers->pluck('id')->all();
        $data['libraries'] = $invitation->mediaServers->flatMap(fn ($s) => collect($invitation->libraryIdsFor($s) ?? [])->map(fn ($id) => $s->id.':'.$id))->values()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Invitation $record */
        DB::transaction(function () use ($record, $data) {
            $record->update(collect($data)->only(['label', 'expires_at', 'max_uses', 'access_days'])->all());
            $record->mediaServers()->sync(InvitationResource::pivotFor((array) $data['servers'], (array) ($data['libraries'] ?? [])));
        });
        Audit::log('invitation.updated', $record, ['label' => $record->label]);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->after(fn (Invitation $record) => Audit::log('invitation.deleted', null, ['label' => $record->label, 'hint' => $record->code_hint]))];
    }
}
