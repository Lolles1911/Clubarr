<?php

namespace App\Filament\Admin;

use Filament\Support\Contracts\HasLabel;

/** The admin menu's groups, in this order. */
enum NavigationGroup: string implements HasLabel
{
    case Members = 'members';
    case Servers = 'servers';
    case Insights = 'insights';
    case System = 'system';

    public function getLabel(): string
    {
        return match ($this) {
            self::Members => __('Members'),
            self::Servers => __('Servers'),
            self::Insights => __('Insights'),
            self::System => __('System'),
        };
    }
}
