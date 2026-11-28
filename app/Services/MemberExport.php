<?php

namespace App\Services;

use App\Models\ServerAccount;
use App\Models\User;

/**
 * Members as CSV, for a spreadsheet. A cell that starts with = + - @ (or a
 * tab) gets a ' first, so a spreadsheet never runs it as a formula.
 * Never passwords, keys or notes.
 */
class MemberExport
{
    public const COLUMNS = ['id', 'name', 'email', 'groups', 'paused', 'access_ends', 'last_seen', 'joined', 'servers'];

    /** @return resource a stream with the CSV, rewound */
    public function stream()
    {
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // so Excel reads æøå right
        fputcsv($out, self::COLUMNS, escape: '');
        User::members()->with(['groups', 'serverAccounts.mediaServer'])->orderBy('id')->chunk(500, function ($users) use ($out) {
            foreach ($users as $u) {
                fputcsv($out, array_map([self::class, 'cell'], [
                    $u->id, $u->name, $u->email, $u->groups->pluck('name')->implode(', '),
                    $u->disabled_at !== null ? 'yes' : 'no',
                    $u->access_expires_at?->toDateTimeString() ?? '',
                    $u->last_seen_at?->toDateTimeString() ?? '',
                    $u->created_at?->toDateTimeString() ?? '',
                    $u->serverAccounts->map(fn (ServerAccount $a) => $a->mediaServer->name.': '.($a->remote_username ?? '?').' ('.$a->status->value.')')->implode('; '),
                ]), escape: '');
            }
        });
        rewind($out);

        return $out;
    }

    public static function cell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
