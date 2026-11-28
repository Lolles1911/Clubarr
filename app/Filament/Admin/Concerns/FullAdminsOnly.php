<?php

namespace App\Filament\Admin\Concerns;

use App\Support\AdminRole;

/** On a page or resource only full admins may open (not moderators). */
trait FullAdminsOnly
{
    public static function canAccess(): bool
    {
        return AdminRole::full();
    }
}
