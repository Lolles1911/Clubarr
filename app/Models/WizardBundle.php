<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of wizard steps chosen on an invitation, instead of the standard ones.
 *
 * @property int $id
 * @property string $name
 */
class WizardBundle extends Model
{
    protected $fillable = ['name'];

    /** @return HasMany<WizardStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WizardStep::class)->orderBy('position');
    }
}
