<?php

namespace App\Filament\Admin\Resources\Maintenances\Pages;

use App\Filament\Admin\Resources\Maintenances\MaintenanceResource;
use App\Jobs\SendAnnouncement;
use App\Models\Announcement;
use App\Models\Maintenance;
use App\Services\Audit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Auth;

class ManageMaintenances extends ManageRecords
{
    protected static string $resource = MaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label(__('Plan maintenance'))
            ->using(function (array $data) {
                $maintenance = new Maintenance(collect($data)->only(['media_server_id', 'starts_at', 'ends_at', 'note'])->all());
                $maintenance->created_by = Auth::id();
                $maintenance->save();
                Audit::log('maintenance.planned', $maintenance, ['when' => $maintenance->describe()]);
                if (! empty($data['tell'])) {
                    self::tell($maintenance);
                }

                return $maintenance;
            })];
    }

    /** Tells the members as a message (mail, Discord, a banner until it ends). */
    public static function tell(Maintenance $maintenance): void
    {
        $announcement = Announcement::create([
            'title' => __('Planned maintenance'),
            'body' => $maintenance->describe().($maintenance->note ? "\n\n".$maintenance->note : '')."\n\n".__('Watching may not work in that time.'),
            'media_server_id' => $maintenance->media_server_id,
            'by_mail' => true, 'by_discord' => true, 'as_banner' => true, 'banner_until' => $maintenance->ends_at,
        ]);
        SendAnnouncement::dispatch($announcement->id);
        $maintenance->forceFill(['told' => true])->save();
    }
}
