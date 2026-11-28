<?php

namespace App\Filament\Admin\Resources\WaitlistEntries\Tables;

use App\Models\WaitlistEntry;
use App\Services\Audit;
use App\Services\Waitlist;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WaitlistEntriesTable
{
    public static function statuses(): array
    {
        return ['waiting' => __('Waiting'), 'invited' => __('Invited'), 'joined' => __('Joined'), 'expired' => __('Not used')];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->searchable()->description(fn (WaitlistEntry $r) => $r->email),
                TextColumn::make('email')->searchable()->hidden(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => self::statuses()[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'waiting' => 'warning', 'invited' => 'info', 'joined' => 'success', default => 'gray'
                    }),
                TextColumn::make('created_at')->label(__('Since'))->since()->sortable(),
                TextColumn::make('invited_at')->label(__('Invited'))->since()->placeholder('—'),
            ])
            ->filters([SelectFilter::make('status')->label(__('Status'))->options(self::statuses())])
            ->recordActions([
                Action::make('inviteNow')->label(__('Invite now'))->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (WaitlistEntry $r) => in_array($r->status, ['waiting', 'expired'], true) && Waitlist::enabled())
                    ->requiresConfirmation()->modalDescription(__('Sends an invitation now, also when the servers are full.'))
                    ->action(function (WaitlistEntry $record) {
                        app(Waitlist::class)->invite($record, Waitlist::template());
                        Notification::make()->title(__('Sent.'))->success()->send();
                    }),
                DeleteAction::make()->after(fn (WaitlistEntry $record) => Audit::log('waitlist.removed', null, ['email' => $record->email])),
            ])
            ->emptyStateHeading(__('Nobody is waiting'))
            ->emptyStateDescription(__('Set a member limit on a server and choose the invitation to copy under Settings → Waiting list.'));
    }
}
