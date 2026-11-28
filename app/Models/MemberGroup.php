<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A group of members with its own rules. A rule left empty is not set by
 * the group; with several groups a member gets the most generous rule.
 *
 * @property int $id
 * @property string $name
 * @property string $color
 * @property bool $no_end access never ends
 * @property int|null $max_streams
 * @property int|null $request_limit
 * @property bool|null $seerr_access
 */
class MemberGroup extends Model
{
    public const COLORS = ['gray', 'primary', 'success', 'warning', 'danger', 'info'];

    protected $fillable = ['name', 'color', 'no_end', 'max_streams', 'request_limit', 'seerr_access'];

    protected function casts(): array
    {
        return ['no_end' => 'boolean', 'max_streams' => 'integer', 'request_limit' => 'integer', 'seerr_access' => 'boolean'];
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function hasRules(): bool
    {
        return $this->no_end || $this->max_streams !== null || $this->request_limit !== null || $this->seerr_access !== null;
    }
}
