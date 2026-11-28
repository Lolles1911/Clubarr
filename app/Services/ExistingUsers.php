<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\VerifiesLogins;
use App\MediaServers\Data\RemoteUser;
use App\MediaServers\MediaServerException;
use App\Models\ExtraUser;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Users that already exist on a media server, brought into Clubarr: listed
 * for the admin (never server administrators, never ones a member has), or
 * proven by a member with the user's own name and password.
 */
class ExistingUsers
{
    /** @return list<RemoteUser> users on the server that no member has, without administrators (kept a minute) */
    public function unlinked(MediaServer $server, bool $fresh = false): array
    {
        $key = 'clubarr:remote-users:'.$server->id;
        if ($fresh) {
            Cache::forget($key);
        }
        // Cached as plain arrays: the cache does not bring objects back.
        $rows = Cache::remember($key, 60, function () use ($server) {
            try {
                return array_map(fn (RemoteUser $u) => $u->toArray(), $server->driver()->users());
            } catch (MediaServerException) {
                return [];
            }
        });
        $linked = $server->accounts()->whereNotNull('remote_id')->pluck('remote_id')
            ->concat(ExtraUser::where('media_server_id', $server->id)->pluck('remote_id'))->map(fn ($id) => (string) $id)->all();

        return array_values(array_filter(array_map(fn (array $row) => RemoteUser::fromArray($row), $rows),
            fn (RemoteUser $u) => ! $u->isAdmin && $u->id !== '' && ! in_array($u->id, $linked, true)));
    }

    /** The member proves a Jellyfin/Emby user is theirs with its name and password. Throws RuntimeException with a reason. */
    public function verify(MediaServer $server, string $username, string $password): RemoteUser
    {
        $driver = $server->driver();
        if (! $driver instanceof VerifiesLogins) {
            throw new RuntimeException(__(':server can\'t check a password this way.', ['server' => $server->name]));
        }
        $remote = $driver->verifyLogin($username, $password);
        if ($remote === null) {
            throw new RuntimeException(__('That user name and password don\'t work on :server.', ['server' => $server->name]));
        }
        $this->checkFree($server, $remote);

        return $remote;
    }

    /** Throws when the user is an administrator, or already someone's in Clubarr. */
    public function checkFree(MediaServer $server, RemoteUser $remote): void
    {
        if ($remote->isAdmin) {
            throw new RuntimeException(__('Administrators of :server can\'t be linked.', ['server' => $server->name]));
        }
        if (ServerAccount::where('media_server_id', $server->id)->where('remote_id', $remote->id)->exists()
            || ExtraUser::where('media_server_id', $server->id)->where('remote_id', $remote->id)->exists()) {
            throw new RuntimeException(__('That user on :server already belongs to someone here. Ask us.', ['server' => $server->name]));
        }
    }

    /** Links the server user to the member. */
    public function link(User $member, MediaServer $server, RemoteUser $remote, string $how): ServerAccount
    {
        $this->checkFree($server, $remote);
        if ($member->serverAccounts()->where('media_server_id', $server->id)->exists()) {
            throw new RuntimeException(__('There is already a user on :server for this account.', ['server' => $server->name]));
        }
        $account = ServerAccount::create([
            'user_id' => $member->id, 'media_server_id' => $server->id, 'remote_id' => $remote->id, 'remote_username' => $remote->username,
            'status' => $remote->isDisabled ? AccountStatus::Disabled : AccountStatus::Active,
        ]);
        Cache::forget('clubarr:remote-users:'.$server->id);
        Audit::log('account.linked', $account, ['server' => $server->name, 'remote_username' => $remote->username, 'how' => $how]);
        app(DiscordSync::class)->sync($member);

        return $account;
    }
}
