<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Services\Audit;
use App\Services\MemberExport;
use App\Support\AdminRole;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Full admins only: every member's email in one file.
            Action::make('export')->label(__('Export (CSV)'))->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => AdminRole::full())
                ->action(function () {
                    abort_unless(AdminRole::full(), 403);
                    Audit::log('members.exported');
                    $stream = app(MemberExport::class)->stream();

                    return response()->streamDownload(function () use ($stream) {
                        fpassthru($stream);
                        fclose($stream);
                    }, 'members-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
                }),
            // Members join with an invitation; here only admins are added.
            CreateAction::make()->label(__('Add admin')),
        ];
    }
}
