<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\Settings;
use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Admin\Resources\MediaServers\MediaServerResource;
use App\Models\Invitation;
use App\Models\MediaServer;
use App\Services\SiteSettings;
use Filament\Widgets\Widget;

/** The first steps, until they are done. */
class GettingStarted extends Widget
{
    protected string $view = 'filament.admin.getting-started';

    protected static ?int $sort = -10;

    protected int|string|array $columnSpan = 'full';

    /** @return list<array{done: bool, label: string, url: string}> */
    public function steps(): array
    {
        return [
            ['done' => MediaServer::exists(), 'label' => __('Add your media server'), 'url' => MediaServerResource::getUrl('create')],
            ['done' => app(SiteSettings::class)->get('mail_enabled') === '1', 'label' => __('Set up mail (for welcome mails and password links)'), 'url' => Settings::getUrl()],
            ['done' => Invitation::exists(), 'label' => __('Make your first invitation'), 'url' => InvitationResource::getUrl('create')],
        ];
    }

    public static function canView(): bool
    {
        return ! MediaServer::exists() || ! Invitation::exists() || app(SiteSettings::class)->get('mail_enabled') !== '1';
    }
}
