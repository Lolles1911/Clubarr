<?php

namespace App\Filament\Admin\Resources\MediaServers\Pages;

use App\Filament\Admin\Resources\MediaServers\MediaServerResource;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Services\Audit;
use App\Services\ServerSync;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditMediaServer extends EditRecord
{
    protected static string $resource = MediaServerResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var MediaServer $record */
        $changed = (clone $record)->fill($data);
        if ($record->url !== $changed->url || array_key_exists('api_key', $data)) {
            try {
                app(ServerSync::class)->check($changed);
            } catch (MediaServerException $e) {
                Notification::make()->title(__('Could not connect with the new address or key'))->body($e->getMessage())->danger()->persistent()->send();
                $this->halt();
            }
        }
        $record->update($data);
        Audit::log('server.updated', $record, ['name' => $record->name, 'key_changed' => array_key_exists('api_key', $data)]);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription(__('The server is removed from Clubarr, with its invitations\' access to it. Users on the server itself are not removed.'))
                ->after(fn (MediaServer $record) => Audit::log('server.deleted', null, ['name' => $record->name])),
        ];
    }
}
