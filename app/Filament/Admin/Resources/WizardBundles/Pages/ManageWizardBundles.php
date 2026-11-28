<?php

namespace App\Filament\Admin\Resources\WizardBundles\Pages;

use App\Filament\Admin\Resources\WizardBundles\WizardBundleResource;
use App\Filament\Admin\Resources\WizardSteps\WizardStepResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWizardBundles extends ManageRecords
{
    protected static string $resource = WizardBundleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('steps')->label(__('Back to the steps'))->color('gray')->url(WizardStepResource::getUrl()),
            CreateAction::make()->label(__('New bundle')),
        ];
    }
}
