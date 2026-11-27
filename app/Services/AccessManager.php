<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\SharesWithPlexAccounts;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Data\Claim;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\MediaServerException;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gives and takes access on the media servers. The only place that changes
 * users on the servers for members, so every change is logged.
 */
class AccessManager
{
    /**
     * Makes the member's users on the invitation's servers they don't have
     * yet. All or nothing: if one server fails, what was made is removed
     * again and the error is thrown.
     *
     * @param  array{identity: PlexIdentity, token: string}|null  $plex
     * @return list<array{server: MediaServer, remote_id: ?string, username: ?string, status: AccountStatus, libraries: ?array, claim: ?Claim}>
     */
    public function provision(User $user, Invitation $invitation, string $username, ?string $password, ?array $plex): array
    {
        $made = [];
        $have = $user->exists ? $user->serverAccounts()->pluck('media_server_id')->all() : [];
        try {
            foreach ($invitation->mediaServers as $server) {
                if (! $server->is_enabled || in_array($server->id, $have, true)) {
                    continue;
                }
                $driver = $server->driver();
                $libraries = $invitation->libraryIdsFor($server);
                if ($driver instanceof CreatesUsers) {
                    if ($password === null) {
                        throw new MediaServerException(__('A password is needed for :server.', ['server' => $server->name]));
                    }
                    $remote = $driver->createUser($username, $password, $libraries);
                    $made[] = ['server' => $server, 'remote_id' => $remote->id, 'username' => $remote->username, 'status' => AccountStatus::Active, 'libraries' => $libraries, 'claim' => null];
                } elseif ($driver instanceof SharesWithPlexAccounts) {
                    if ($plex === null) {
                        throw new MediaServerException(__('Sign in with Plex first.'));
                    }
                    $remote = $driver->share($plex['identity'], $libraries);
                    $accepted = false;
                    try {
                        $accepted = $driver->acceptShare($plex['token']);
                    } catch (Throwable $e) {
                        Log::info('Plex share made, but not accepted for the member: '.$e->getMessage());
                    }
                    $made[] = ['server' => $server, 'remote_id' => $remote->id, 'username' => $remote->username, 'status' => $accepted ? AccountStatus::Active : AccountStatus::Pending, 'libraries' => $libraries, 'claim' => null];
                } elseif ($driver instanceof UsesClaimLinks) {
                    $claim = $driver->createClaim($libraries);
                    $made[] = ['server' => $server, 'remote_id' => null, 'username' => null, 'status' => AccountStatus::Pending, 'libraries' => $libraries, 'claim' => $claim];
                }
            }
        } catch (Throwable $e) {
            $this->undo($made);

            throw $e;
        }

        return $made;
    }

    /** Removes users made by provision() when the rest failed. */
    public function undo(array $made): void
    {
        foreach (array_reverse($made) as $row) {
            if ($row['remote_id'] === null) {
                continue; // a Silo link: nothing was made yet
            }
            try {
                $row['server']->driver()->delete($row['remote_id'], (string) $row['username']);
            } catch (Throwable $e) {
                Log::warning('Clubarr: could not remove '.$row['server']->name.' user '.$row['remote_id'].' after a failed sign-up: '.$e->getMessage());
            }
        }
    }

    /** Saves what provision() made as the member's server accounts. */
    public function record(User $user, Invitation $invitation, array $made): void
    {
        foreach ($made as $row) {
            ServerAccount::create([
                'user_id' => $user->id,
                'media_server_id' => $row['server']->id,
                'invitation_id' => $invitation->id,
                'remote_id' => $row['remote_id'],
                'remote_username' => $row['username'],
                'status' => $row['status'],
                'library_ids' => $row['libraries'],
                'claim_url' => $row['claim']?->url,
                'claim_id' => $row['claim']?->id,
            ]);
        }
    }

    /** Stops the member from watching on every server. Returns the servers that failed. */
    public function disableAll(User $user, string $reason): array
    {
        $failed = [];
        foreach ($user->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Active)->get() as $account) {
            try {
                if ($account->remote_id !== null) {
                    $account->mediaServer->driver()->disable($account->remote_id, (string) $account->remote_username);
                }
                $account->update(['status' => AccountStatus::Disabled]);
                Audit::log('account.disabled', $account, ['server' => $account->mediaServer->name, 'reason' => $reason]);
            } catch (Throwable $e) {
                $failed[] = $account->mediaServer->name.': '.$e->getMessage();
            }
        }

        return $failed;
    }

    /** Lets the member watch again on every server. Returns the servers that failed. */
    public function enableAll(User $user): array
    {
        $failed = [];
        foreach ($user->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Disabled)->get() as $account) {
            try {
                if ($account->remote_id !== null) {
                    $account->mediaServer->driver()->enable($account->remote_id, (string) $account->remote_username, $account->library_ids);
                }
                $account->update(['status' => AccountStatus::Active]);
                Audit::log('account.enabled', $account, ['server' => $account->mediaServer->name]);
            } catch (Throwable $e) {
                $failed[] = $account->mediaServer->name.': '.$e->getMessage();
            }
        }

        return $failed;
    }

    /** Removes the member's users from the servers (and the records). Returns the servers that failed. */
    public function removeAll(User $user): array
    {
        $failed = [];
        foreach ($user->serverAccounts()->with('mediaServer')->get() as $account) {
            try {
                if ($account->remote_id !== null) {
                    $account->mediaServer->driver()->delete($account->remote_id, (string) $account->remote_username);
                }
                Audit::log('account.removed', $user, ['server' => $account->mediaServer->name, 'remote_username' => $account->remote_username]);
                $account->delete();
            } catch (Throwable $e) {
                $failed[] = $account->mediaServer->name.': '.$e->getMessage();
            }
        }

        return $failed;
    }

    /** Pending Silo links and Plex shares that the member has taken in the meantime. */
    public function refreshPending(ServerAccount $account): void
    {
        if ($account->status !== AccountStatus::Pending) {
            return;
        }
        $driver = $account->mediaServer->driver();
        try {
            if ($driver instanceof UsesClaimLinks && $account->claim_id !== null) {
                $remote = $driver->claimedUser($account->claim_id);
                if ($remote !== null) {
                    $account->update(['remote_id' => $remote->id, 'remote_username' => $remote->username, 'status' => AccountStatus::Active, 'claim_url' => null]);
                    Audit::log('account.claimed', $account, ['server' => $account->mediaServer->name], $account->user_id);
                }
            } elseif ($driver instanceof SharesWithPlexAccounts && $account->remote_id !== null) {
                if (collect($driver->users())->contains(fn ($u) => $u->id === $account->remote_id)) {
                    $account->update(['status' => AccountStatus::Active]);
                }
            }
        } catch (Throwable $e) {
            Log::info('Clubarr: pending account '.$account->id.' not checked: '.$e->getMessage());
        }
    }
}
