<?php

namespace App\Services;

use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Admin\Resources\ExtensionRequests\ExtensionRequestResource;
use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Admin\Resources\MediaServers\MediaServerResource;
use App\Filament\Admin\Resources\ProblemReports\ProblemReportResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\AccessRequest;
use App\Models\ExtensionRequest;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Models\ProblemReport;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Checks": what may be wrong or waiting, worst first, each with where to fix
 * it. Only reads; nothing secret is shown.
 */
class Checks
{
    /** @return list<array{level: string, title: string, detail: string, url: ?string}> level: bad, warn, todo, ok */
    public function all(): array
    {
        $s = app(SiteSettings::class);
        $rows = [];
        $add = function (string $level, string $title, string $detail, ?string $url = null) use (&$rows) {
            $rows[] = compact('level', 'title', 'detail', 'url');
        };

        foreach (MediaServer::where('is_enabled', true)->whereNotNull('last_error')->get() as $server) {
            $add('bad', __(':server does not answer', ['server' => $server->name]), (string) $server->last_error, MediaServerResource::getUrl('edit', ['record' => $server]));
        }
        $beat = (int) Cache::get('clubarr:heartbeat', 0);
        $beat === 0 || $beat < now()->subHours(3)->getTimestamp()
            ? $add('bad', __('The scheduler does not seem to run'), __('Ending access, reminders and sync run every hour. In Docker this starts by itself; check the container\'s log.'))
            : $add('ok', __('The scheduler runs'), __('Last run :time.', ['time' => now()->setTimestamp($beat)->diffForHumans()]));
        try {
            $stuck = DB::table('jobs')->where('created_at', '<', now()->subMinutes(15)->getTimestamp())->count();
            $stuck > 0
                ? $add('bad', __('Jobs are waiting in the queue'), __(':count jobs have waited over 15 minutes (mails, messages). The queue worker may be stopped.', ['count' => $stuck]))
                : $add('ok', __('The queue works'), __('No jobs are waiting.'));
        } catch (Throwable) {
        }
        if ($s->get('mail_enabled') !== '1') {
            $add('warn', __('Mail is off'), __('No welcome mails, password links, reminders or invitations by mail.'), Settings::getUrl(['tab' => 'mail']));
        }
        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $add('warn', __('Not on https'), __('APP_URL is :url. Behind a reverse proxy with https, set APP_URL and SESSION_SECURE_COOKIE=true.', ['url' => (string) config('app.url')]));
        }
        if ($s->get('admin_2fa_required') === '0') {
            $add('warn', __('Admins may sign in without two-factor'), __('A stolen admin password is then enough.'), Settings::getUrl(['tab' => 'security']));
        }
        $without = User::where('is_admin', true)->whereNull('app_authentication_secret')->count();
        if ($without > 0) {
            $add('warn', __('Admins without two-factor sign-in: :count', ['count' => $without]), __('They set it up on My page.'), UserResource::getUrl());
        }
        if (Backups::supported()) {
            $newest = collect(Backups::list())->max('time');
            $newest === null || $newest < now()->subDays(2)->getTimestamp()
                ? $add('warn', __('No backup in the last two days'), __('Backups are made every night in /config/backups.'), Settings::getUrl(['tab' => 'backup']))
                : $add('ok', __('Backups are made'), __('Newest :time.', ['time' => now()->setTimestamp((int) $newest)->diffForHumans()]));
        }
        if (($update = UpdateCheck::available()) !== null) {
            $add('warn', __('Clubarr :version is out', ['version' => $update['version']]), __('In Unraid: Docker → Check for updates → apply update.'), $update['url'] ?: null);
        }
        if (! app(Watching::class)->enabled() && User::members()->whereNotNull('max_streams')->exists()) {
            $add('warn', __('Stream limits are not checked'), __('Some members have a limit on streams, but neither Tracearr nor Tautulli is set up.'));
        }
        $pending = [
            [AccessRequest::where('status', 'pending')->count(), __('applications'), AccessRequestResource::getUrl()],
            [ExtensionRequest::where('status', 'pending')->count(), __('requests for more time'), ExtensionRequestResource::getUrl()],
            [ProblemReport::where('status', 'open')->whereNull('replied_at')->count(), __('problems without an answer'), ProblemReportResource::getUrl()],
            [Invitation::where('awaiting_approval', true)->whereNull('revoked_at')->count(), __('invitations waiting for approval'), InvitationResource::getUrl()],
        ];
        foreach ($pending as [$count, $what, $url]) {
            if ($count > 0) {
                $add('todo', __('Waiting: :count :what', ['count' => $count, 'what' => $what]), __('Waiting for you.'), $url);
            }
        }
        $unlinked = MediaServer::all()->sum(fn (MediaServer $m) => (int) $m->setting('unlinked', 0));
        if ($unlinked > 0) {
            $add('todo', __('Users on the servers not in Clubarr: :count', ['count' => $unlinked]), __('Import them, or clean them up, under Media servers.'), MediaServerResource::getUrl());
        }

        $order = ['bad' => 0, 'warn' => 1, 'todo' => 2, 'ok' => 3];
        usort($rows, fn ($a, $b) => $order[$a['level']] <=> $order[$b['level']]);

        return $rows;
    }
}
