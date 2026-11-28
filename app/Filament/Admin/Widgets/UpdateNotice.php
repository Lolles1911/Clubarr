<?php

namespace App\Filament\Admin\Widgets;

use App\Services\UpdateCheck;
use Filament\Widgets\Widget;

/** "Clubarr x.y.z is out", while a newer release than this one exists. */
class UpdateNotice extends Widget
{
    protected string $view = 'filament.admin.update-notice';

    protected static ?int $sort = -20;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    /** @return array{version: string, url: string}|null */
    public function update(): ?array
    {
        return UpdateCheck::available();
    }

    public static function canView(): bool
    {
        return UpdateCheck::available() !== null;
    }
}
