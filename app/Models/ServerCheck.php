<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One check of a media server (every five minutes): whether it answered.
 * For uptime on the status page; kept 90 days.
 *
 * @property int $media_server_id
 * @property bool $up
 * @property Carbon $checked_at
 */
class ServerCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['media_server_id', 'up', 'checked_at'];

    protected function casts(): array
    {
        return ['up' => 'boolean', 'checked_at' => 'datetime'];
    }

    /** Percent of checks that answered over the last days, or null without checks. */
    public static function uptime(MediaServer $server, int $days): ?float
    {
        $row = self::query()->where('media_server_id', $server->id)->where('checked_at', '>=', now()->subDays($days))
            ->selectRaw('count(*) as n, sum(case when up then 1 else 0 end) as ok')->first();
        $n = (int) ($row->n ?? 0);

        return $n === 0 ? null : round(100 * (int) $row->ok / $n, 1);
    }
}
