<?php

namespace App\Services;

use App\Enums\AccountStatus;
use App\MediaServers\Contracts\CreatesUsers;
use App\Models\ExtraUser;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * Extra users for a member's family (Jellyfin, Emby), up to the number the
 * admin allows. Each gets the member's libraries on that server and follows
 * the member: paused, let back in and removed together.
 */
class FamilyUsers
{
    public static function max(): int
    {
        return max(0, (int) app(SiteSettings::class)->get('extra_users_max'));
    }

    /** @return Collection<int, ServerAccount> the member's active users where an extra user can be made */
    public function servers(User $member): Collection
    {
        return $member->serverAccounts()->with('mediaServer')->where('status', AccountStatus::Active)->get()
            ->filter(fn (ServerAccount $a) => $a->mediaServer->is_enabled && $a->mediaServer->type->can(CreatesUsers::class))->values();
    }

    public function remaining(User $member): int
    {
        return max(0, self::max() - $member->extraUsers()->count());
    }

    public function add(User $member, int $serverId, string $label, string $username, string $password): ExtraUser
    {
        if ($this->remaining($member) < 1 || ! $member->hasActiveAccess()) {
            throw new RuntimeException(__('You can\'t add more users.'));
        }
        $account = $this->servers($member)->firstWhere('media_server_id', $serverId);
        if ($account === null) {
            throw new RuntimeException(__('Choose one of your servers.'));
        }
        /** @var CreatesUsers $driver */
        $driver = $account->mediaServer->driver();
        $remote = $driver->createUser($username, $password, $account->library_ids);
        $extra = $member->extraUsers()->create([
            'media_server_id' => $serverId, 'remote_id' => $remote->id, 'remote_username' => $remote->username,
            'label' => mb_substr(trim($label), 0, 60), 'status' => AccountStatus::Active,
        ]);
        Audit::log('family.added', $member, ['server' => $account->mediaServer->name, 'remote_username' => $remote->username], $member->id);

        return $extra;
    }

    /** Removes an extra user from its server. Throws when the server refuses. */
    public function remove(ExtraUser $extra): void
    {
        $extra->mediaServer->driver()->delete($extra->remote_id, $extra->remote_username);
        Audit::log('family.removed', $extra->user, ['server' => $extra->mediaServer->name, 'remote_username' => $extra->remote_username]);
        $extra->delete();
    }

    /** With the member: 'disable', 'enable' or 'remove' every extra user. Returns what failed. */
    public function follow(User $member, string $what): array
    {
        $failed = [];
        foreach ($member->extraUsers()->with('mediaServer')->get() as $extra) {
            try {
                $driver = $extra->mediaServer->driver();
                match ($what) {
                    'disable' => $extra->status === AccountStatus::Active ? $driver->disable($extra->remote_id, $extra->remote_username) : null,
                    'enable' => $extra->status === AccountStatus::Disabled ? $driver->enable($extra->remote_id, $extra->remote_username, null) : null,
                    'remove' => $driver->delete($extra->remote_id, $extra->remote_username),
                };
                $what === 'remove' ? $extra->delete() : $extra->update(['status' => $what === 'disable' ? AccountStatus::Disabled : AccountStatus::Active]);
            } catch (Throwable $e) {
                $failed[] = $extra->mediaServer->name.' ('.$extra->remote_username.'): '.$e->getMessage();
            }
        }

        return $failed;
    }
}
