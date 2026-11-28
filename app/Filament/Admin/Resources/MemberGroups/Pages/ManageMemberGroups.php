<?php

namespace App\Filament\Admin\Resources\MemberGroups\Pages;

use App\Filament\Admin\Resources\MemberGroups\MemberGroupResource;
use App\Models\MemberGroup;
use App\Services\Audit;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageMemberGroups extends ManageRecords
{
    protected static string $resource = MemberGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->after(fn (MemberGroup $record) => Audit::log('group.created', $record, ['name' => $record->name]))];
    }
}
