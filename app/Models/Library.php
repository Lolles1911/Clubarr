<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A library on a media server, as last read from it.
 *
 * @property string $external_id
 * @property string $name
 */
class Library extends Model
{
    protected $fillable = ['media_server_id', 'external_id', 'name', 'kind'];

    /** @return BelongsTo<MediaServer, $this> */
    public function mediaServer(): BelongsTo
    {
        return $this->belongsTo(MediaServer::class);
    }
}
