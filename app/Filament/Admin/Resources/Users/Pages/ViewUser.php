<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\AuditLog;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Watching;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Collection;
use Throwable;

/** Everything about one member in one place. */
class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected string $view = 'filament.admin.member';

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = $this->getRecord();

        return $user->loadMissing(['serverAccounts.mediaServer.libraries', 'groups', 'mediaRequests']);
    }

    /** Who invited them, and with what. */
    #[Unexposed]
    public function invitation(): ?Invitation
    {
        $id = $this->member()->serverAccounts->pluck('invitation_id')->filter()->first();

        return $id ? Invitation::with('creator')->find($id) : null;
    }

    /** @return array{stats: ?array, devices: list<array>} */
    #[Unexposed]
    public function watching(): array
    {
        $watching = app(Watching::class);
        if (! $watching->enabled()) {
            return ['stats' => null, 'devices' => []];
        }
        try {
            return ['stats' => $watching->statsOf($this->member()), 'devices' => array_slice($watching->devicesOf($this->member()), 0, 6)];
        } catch (Throwable) {
            return ['stats' => null, 'devices' => []];
        }
    }

    /** @return Collection<int, AuditLog> */
    #[Unexposed]
    public function log(): Collection
    {
        $user = $this->member();

        return AuditLog::where(fn ($q) => $q->where('actor_id', $user->id)->orWhere(fn ($q) => $q->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)))
            ->latest('id')->limit(15)->get();
    }
}
