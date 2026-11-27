<?php

namespace App\Filament\Admin\Resources\MediaServers\Pages;

use App\Filament\Admin\Resources\MediaServers\MediaServerResource;
use App\MediaServers\MediaServerException;
use App\Models\MediaServer;
use App\Services\Audit;
use App\Services\ServerSync;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** A server is only saved when Clubarr can reach it with the key. */
class CreateMediaServer extends CreateRecord
{
    protected static string $resource = MediaServerResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $server = new MediaServer($data);
        try {
            app(ServerSync::class)->check($server);
        } catch (MediaServerException $e) {
            Notification::make()->title(__('Could not connect'))->body($e->getMessage())->danger()->persistent()->send();
            $this->halt();
        }
        $server->save();
        try {
            $count = app(ServerSync::class)->syncLibraries($server);
            Notification::make()->title(__('Connected. :count libraries found.', ['count' => $count]))->success()->send();
        } catch (MediaServerException $e) {
            Notification::make()->title(__('Connected, but the libraries could not be read'))->body($e->getMessage())->warning()->send();
        }
        Audit::log('server.created', $server, ['name' => $server->name, 'type' => $server->type->value]);

        return $server;
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
