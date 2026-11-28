<?php

namespace App\Filament\Admin\Resources\WizardSteps\Pages;

use App\Filament\Admin\Resources\WizardBundles\WizardBundleResource;
use App\Filament\Admin\Resources\WizardSteps\WizardStepResource;
use App\Models\MediaServer;
use App\Models\WizardBundle;
use App\Models\WizardStep;
use App\Services\Audit;
use App\Services\WizardSteps;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageWizardSteps extends ManageRecords
{
    protected static string $resource = WizardStepResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bundles')->label(__('Bundles'))->icon(Heroicon::OutlinedRectangleStack)->color('gray')->url(WizardBundleResource::getUrl()),
            Action::make('preset')->label(__('Add ready-made steps'))->icon(Heroicon::OutlinedSparkles)->color('gray')
                ->modalDescription(__('"What is it?", "Get the app" and "Tips" for the server\'s type. You can change them afterwards.'))
                ->schema([
                    Select::make('server')->label(__('Server'))->required()->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('bundle')->label(__('Bundle'))->placeholder(__('Standard steps'))->options(fn () => WizardBundle::orderBy('name')->pluck('name', 'id')->all()),
                ])
                ->action(function (array $data) {
                    $server = MediaServer::findOrFail((int) $data['server']);
                    $position = (int) WizardStep::max('position');
                    foreach (WizardSteps::preset($server->type, $server) as $step) {
                        WizardStep::create($step + ['media_server_id' => $server->id, 'wizard_bundle_id' => filled($data['bundle'] ?? null) ? (int) $data['bundle'] : null, 'position' => ++$position]);
                    }
                    Audit::log('wizard.preset_added', $server, ['server' => $server->name]);
                    Notification::make()->title(__('Added. Change the texts as you like.'))->success()->send();
                }),
            CreateAction::make()->label(__('New step'))
                ->mutateDataUsing(fn (array $data) => $data + ['position' => (int) WizardStep::max('position') + 1])
                ->after(fn (WizardStep $record) => Audit::log('wizard.step_created', $record, ['title' => $record->title])),
        ];
    }
}
