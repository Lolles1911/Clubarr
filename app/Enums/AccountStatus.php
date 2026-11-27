<?php

namespace App\Enums;

enum AccountStatus: string
{
    /** Waiting for the member: a Silo claim link or a Plex share not accepted yet. */
    case Pending = 'pending';
    case Active = 'active';
    case Disabled = 'disabled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Waiting for the member'),
            self::Active => __('Active'),
            self::Disabled => __('Disabled'),
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            self::Disabled => 'gray',
        };
    }
}
