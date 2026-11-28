<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\ChangesLibraries;
use App\MediaServers\Contracts\CreatesUsers;
use App\MediaServers\Contracts\SharesWithPlexAccounts;
use App\MediaServers\Contracts\UsesClaimLinks;
use App\MediaServers\Data\Claim;
use App\MediaServers\Data\PlexIdentity;
use App\MediaServers\Data\RemoteUser;
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
     * @param  array<int, RemoteUser>  $linked  server ID => a user they already have there
     * @return list<array{server: MediaServer, remote_id: ?string, username: ?string, status: AccountStatus, libraries: ?array, claim: ?Claim}>
     */
    public function provision(User $user, Invitation $invitation, string $username, ?string $password, ?array $plex, array $linked = []): array
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
                if (isset($linked[$server->id])) {
                    // A user they already have: checked again, then given the invitation's libraries. Never deleted on undo.
                    $remote = $linked[$server->id];
                    app(ExistingUsers::class)->checkFree($server, $remote);
                    $driver->enable($remote->id, $remote->username, $libraries);
                    if ($driver instanceof ChangesLibraries) {
                        $driver->setLibraries($remote->id, $libraries);
                    }
                    $made[] = ['server' => $server, 'remote_id' => $remote->id, 'username' => $remote->username, 'status' => AccountStatus::Active, 'libraries' => $libraries, 'claim' => null, 'linked' => true];
                } elseif ($driver instanceof CreatesUsers) {
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
                        // Without a token (the member typed their Plex email), they accept it in Plex.
                        $accepted = $plex['token'] !== '' && $driver->acceptShare($plex['token']);
                    } catch (Throwable $e) {
                        Log::info('Plex share made, but not accepted for the member: '.$e->getMessage());
                    }
                    $made[] = ['server' => $server, 'remote_id' => $remote->id !== '' ? $remote->id : null, 'username' => $remote->username, 'status' => $accepted ? AccountStatus::Active : AccountStatus::Pending, 'libraries' => $libraries, 'claim' => null];
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
            if ($row['remote_id'] === null || ! empty($row['linked'])) {
                continue; // a Silo link (nothing made yet), or a user they already had
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

        // Their family's extra users go along.
        array_push($failed, ...app(FamilyUsers::class)->follow($user, 'disable'));

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

        // Their family's extra users go along.
        array_push($failed, ...app(FamilyUsers::class)->follow($user, 'enable'));

        return $failed;
    }

    /**
     * Changes which libraries a member has on a server. A paused user only
     * gets the new choice stored; it is used when they are let back in.
     *
     * @param  array<int, string>|null  $libraryIds  null = all
     */
    public function changeLibraries(ServerAccount $account, ?array $libraryIds): void
    {
        $driver = $account->mediaServer->driver();
        if ($account->status === AccountStatus::Active && $account->remote_id !== null) {
            if (! $driver instanceof ChangesLibraries) {
                throw new MediaServerException(__('Change the libraries on :server itself.', ['server' => $account->mediaServer->name]));
            }
            $driver->setLibraries($account->remote_id, $libraryIds);
        }
        $account->update(['library_ids' => $libraryIds]);
        Audit::log('account.libraries_changed', $account, ['server' => $account->mediaServer->name, 'libraries' => $libraryIds === null ? 'all' : count($libraryIds)]);
    }

    /** Removes one of the member's users from its server (and the record). Throws when the server refuses. */
    public function removeOne(ServerAccount $account): void
    {
        if ($account->remote_id !== null) {
            $account->mediaServer->driver()->delete($account->remote_id, (string) $account->remote_username);
        }
        Audit::log('account.removed', $account->user, ['server' => $account->mediaServer->name, 'remote_username' => $account->remote_username]);
        $account->delete();
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

        // Their family's extra users go along.
        array_push($failed, ...app(FamilyUsers::class)->follow($user, 'remove'));

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
            } elseif ($driver instanceof SharesWithPlexAccounts) {
                // Known by ID after "Sign in with Plex"; by the email or name the member typed otherwise.
                $typed = mb_strtolower((string) $account->remote_username);
                $remote = collect($driver->users())->first(fn ($u) => $account->remote_id !== null
                    ? $u->id === $account->remote_id
                    : $typed !== '' && in_array($typed, [mb_strtolower($u->username), mb_strtolower((string) $u->email)], true));
                if ($remote !== null && $remote->id !== '') {
                    $account->update(['remote_id' => $remote->id, 'remote_username' => $remote->username, 'status' => AccountStatus::Active]);
                }
            }
        } catch (Throwable $e) {
            Log::info('Clubarr: pending account '.$account->id.' not checked: '.$e->getMessage());
        }
    }
}
