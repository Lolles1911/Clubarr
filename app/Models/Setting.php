<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One site setting. Read and written through App\Services\SiteSettings,
 * which encrypts the secret ones.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];
}
