<?php

namespace App\Services;

use App\MediaServers\Data\RemoteUser;
use App\Models\Claim;
use App\Models\MediaServer;
use App\Models\ServerAccount;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Bringing users who already exist on a media server into Clubarr, many at
 * once. With an email the server knows (Plex, Silo): a member, who gets a
 * mail to choose their password (or the member with that email, linked).
 * Without (Jellyfin, Emby): a personal one-time link to send them.
 */
class UserImport
{
    public function __construct(private MemberAccounts $members) {}

    /**
     * @param  list<RemoteUser>  $users  users on the server that no member has (no administrators)
     * @return list<array{username: string, result: string, link: ?string}> result: mailed, linked, link, failed
     */
    public function import(MediaServer $server, array $users): array
    {
        $rows = [];
        foreach ($users as $remote) {
            try {
                $rows[] = $this->one($server, $remote);
            } catch (Throwable $e) {
                report($e);
                $rows[] = ['username' => $remote->username, 'result' => 'failed', 'link' => null];
            }
        }
        Audit::log('account.imported_many', $server, ['server' => $server->name, 'count' => count($users)]);

        return $rows;
    }

    /** @return array{username: string, result: string, link: ?string} */
    private function one(MediaServer $server, RemoteUser $remote): array
    {
        $email = $remote->email !== null && filter_var($remote->email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($remote->email) : null;
        if ($email === null) {
            $claim = new Claim(['media_server_id' => $server->id, 'remote_id' => $remote->id, 'remote_username' => $remote->username]);
            $claim->created_by = Auth::id();
            $claim->expires_at = now()->addDays(Claim::DAYS);
            $code = $claim->assignCode();
            $claim->save();

            return ['username' => $remote->username, 'result' => 'link', 'link' => route('filament.app.claim', ['code' => $code])];
        }

        $existing = User::where('email', $email)->first();
        DB::transaction(function () use ($server, $remote, $email, $existing) {
            $user = $existing ?? $this->members->create($remote->username, $email);
            ServerAccount::create(['user_id' => $user->id, 'media_server_id' => $server->id, 'remote_id' => $remote->id,
                'remote_username' => $remote->username, 'status' => $remote->isDisabled ? 'disabled' : 'active']);
        });

        return ['username' => $remote->username, 'result' => $existing ? 'linked' : 'mailed', 'link' => null];
    }
}
