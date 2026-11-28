<?php

namespace App\Services;

use App\Enums\AccountStatus;
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
        if ($server->exists && $info->version !== null) {
            MediaServer::whereKey($server->id)->update(['settings' => json_encode(array_merge($server->fresh()->settings ?? [], ['version' => $info->version]))]);
        }

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

    /**
     * Brings the members' users up to date with the server: disabled or
     * active as the server says, and counts users the server doesn't have
     * any more (left alone; an admin decides).
     *
     * @return array{changed: int, missing: int}
     */
    public function syncUsers(MediaServer $server): array
    {
        $remote = collect($server->driver()->users())->keyBy('id');
        $changed = $missing = 0;
        foreach ($server->accounts()->whereNotNull('remote_id')->where('status', '!=', AccountStatus::Pending)->get() as $account) {
            $user = $remote->get((string) $account->remote_id);
            if ($user === null) {
                $missing++;

                continue;
            }
            $status = $user->isDisabled ? AccountStatus::Disabled : AccountStatus::Active;
            if ($account->status !== $status || $account->remote_username !== $user->username) {
                $account->update(['status' => $status, 'remote_username' => $user->username]);
                $changed++;
            }
        }
        foreach ($server->accounts()->with('mediaServer')->where('status', AccountStatus::Pending)->get() as $account) {
            app(AccessManager::class)->refreshPending($account);
        }

        return ['changed' => $changed, 'missing' => $missing];
    }

    private function note(MediaServer $server, ?string $error): void
    {
        if (! $server->exists) {
            return;
        }
        $was = MediaServer::whereKey($server->id)->value('last_error');
        // A query, not save(): $server may hold unsaved changes (a new key being tried).
        MediaServer::whereKey($server->id)->update(['last_checked_at' => now(), 'last_error' => $error === null ? null : mb_substr($error, 0, 500)]);
        if (($was === null) !== ($error === null)) {
            Audit::log($error === null ? 'server.up' : 'server.down', $server, $error === null ? [] : ['error' => mb_substr($error, 0, 200)]);
        }
    }
}
