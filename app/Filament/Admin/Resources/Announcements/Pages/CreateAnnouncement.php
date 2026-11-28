<?php

namespace App\Filament\Admin\Resources\Announcements\Pages;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\SiteSettings;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateAnnouncement extends CreateRecord
{
    protected static string $resource = AnnouncementResource::class;

    protected static bool $canCreateAnother = false;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // What can't be sent is off, whatever the browser sent.
        $data['by_mail'] = ! empty($data['by_mail']) && app(SiteSettings::class)->get('mail_enabled') === '1';
        $data['by_discord'] = ! empty($data['by_discord']) && (new DiscordSync)->enabled();
        $data['banner_until'] = ! empty($data['as_banner']) ? ($data['banner_until'] ?? null) : null;

        return $data;
    }

    protected function afterCreate(): void
    {
        /** @var Announcement $announcement */
        $announcement = $this->record;
        $announcement->forceFill(['created_by' => Auth::id()])->save();
        $count = $announcement->recipients()->count();
        Audit::log('message.sent', $announcement, ['title' => $announcement->title, 'members' => $count]);
        if ($announcement->by_mail || $announcement->by_discord) {
            SendAnnouncement::dispatch($announcement->id);
        } else {
            $announcement->forceFill(['sent_at' => now()])->save();
        }
        Notification::make()->title(__('Sent to :count members.', ['count' => $count]))->success()->send();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }
}
