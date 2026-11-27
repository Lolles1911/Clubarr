<?php

namespace App\Services;

use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\Models\Library;
use App\Models\MediaServer;
use Throwable;

/** Checks a media server and reads its libraries into Clubarr. */
class ServerSync
{
    /** Throws MediaServerException with a reason the admin can act on. */
    public function check(MediaServer $server): ServerInfo
    {
        try {
            $info = $server->driver()->test();
        } catch (MediaServerException $e) {
            $this->note($server, $e->getMessage());

            throw $e;
        } catch (Throwable $e) {
            report($e);
            $this->note($server, __('Unexpected error: :class', ['class' => class_basename($e)]));

            throw new MediaServerException(__('Unexpected error while checking :name. See the log.', ['name' => $server->name]));
        }
        $this->note($server, null);

        return $info;
    }

    /** Reads the libraries; ones gone from the server are removed. Returns how many there are. */
    public function syncLibraries(MediaServer $server): int
    {
        $libraries = $server->driver()->libraries();
        $seen = [];
        foreach ($libraries as $library) {
            if ($library->id === '') {
                continue;
            }
            Library::updateOrCreate(['media_server_id' => $server->id, 'external_id' => $library->id], ['name' => $library->name, 'kind' => $library->kind]);
            $seen[] = $library->id;
        }
        $server->libraries()->whereNotIn('external_id', $seen)->delete();

        return count($seen);
    }

    private function note(MediaServer $server, ?string $error): void
    {
        if (! $server->exists) {
            return;
        }
        // A query, not save(): $server may hold unsaved changes (a new key being tried).
        MediaServer::whereKey($server->id)->update(['last_checked_at' => now(), 'last_error' => $error === null ? null : mb_substr($error, 0, 500)]);
    }
}
