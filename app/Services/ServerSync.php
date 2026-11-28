<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Data\ServerInfo;
use App\MediaServers\MediaServerException;
use App\Models\Library;
use App\Models\MediaServer;
use App\Models\ServerAccount;
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
     * Brings Clubarr up to date with the server's users: disabled or active
     * as the server says; a user deleted on the server loses that access in
     * Clubarr (a member left with no servers is paused, not deleted); and
     * counts the server's users that no member has. When most users seem
     * gone at once, nothing is removed: that looks like a server problem.
     *
     * @return array{changed: int, missing: int, removed: int, unlinked: int, held: bool}
     */
    public function syncUsers(MediaServer $server): array
    {
        $remote = collect($server->driver()->users())->keyBy('id');
        $changed = 0;
        $gone = [];
        $accounts = $server->accounts()->with('user')->whereNotNull('remote_id')->where('status', '!=', AccountStatus::Pending)->get();
        foreach ($accounts as $account) {
            $user = $remote->get((string) $account->remote_id);
            if ($user === null) {
                $gone[] = $account;

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

        $held = $gone !== [] && (count($gone) > max(3, intdiv($accounts->count(), 2)) || $remote->isEmpty());
        if ($held) {
            Audit::log('server.sync_held', $server, ['missing' => count($gone)]);
        } else {
            foreach ($gone as $account) {
                $this->forgetGone($account);
            }
        }

        $linked = $server->accounts()->whereNotNull('remote_id')->pluck('remote_id')->map(fn ($id) => (string) $id)->all();
        $unlinked = $remote->reject(fn ($u) => $u->isAdmin || $u->id === '' || in_array($u->id, $linked, true))->count();
        MediaServer::whereKey($server->id)->update(['settings' => json_encode(array_merge($server->fresh()->settings ?? [], ['unlinked' => $unlinked]))]);

        return ['changed' => $changed, 'missing' => count($gone), 'removed' => $held ? 0 : count($gone), 'unlinked' => $unlinked, 'held' => $held];
    }

    /** A user deleted on the server: the access goes in Clubarr; a member left with none is paused. */
    private function forgetGone(ServerAccount $account): void
    {
        $member = $account->user;
        $server = $account->mediaServer;
        $account->delete();
        Audit::log('account.gone_on_server', $member, ['server' => $server->name, 'remote_username' => $account->remote_username], null);
        if (! $member->is_admin && $member->disabled_at === null && ! $member->serverAccounts()->exists()) {
            $member->forceFill(['disabled_at' => now()])->save();
            Audit::log('member.disabled', $member, ['reason' => 'no servers left'], null);
            app(DiscordSync::class)->sync($member);
        }
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
