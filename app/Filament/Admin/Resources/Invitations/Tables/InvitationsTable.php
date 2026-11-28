<?php

namespace App\Filament\Admin\Resources\Invitations\Tables;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Invitation;
use App\Services\Audit;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvitationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['mediaServers', 'accounts.user']))
            ->columns([
                TextColumn::make('label')->label(__('Note'))->searchable()->placeholder('—')
                    ->description(fn (Invitation $record) => $record->code_hint.'…'),
                TextColumn::make('mediaServers.name')->visibleFrom('sm')->label(__('Servers'))->badge(),
                TextColumn::make('uses')->label(__('Used'))
                    ->state(fn (Invitation $record) => $record->uses.' / '.($record->max_uses ?? '∞')),
                TextColumn::make('used_by')->visibleFrom('md')->label(__('Used by'))->badge()->color('gray')->placeholder('—')
                    ->state(fn (Invitation $record) => $record->accounts->pluck('user.name')->filter()->unique()->values()->all())
                    ->url(fn (Invitation $record) => ($u = $record->accounts->first()?->user) ? UserResource::getUrl('edit', ['record' => $u]) : null),
                TextColumn::make('access_days')->visibleFrom('md')->label(__('Access'))
                    ->state(fn (Invitation $record) => $record->access_days ? __(':days days', ['days' => $record->access_days]) : __('No end')),
                TextColumn::make('expires_at')->visibleFrom('lg')->label(__('Link valid until'))->dateTime('j M Y, H:i')->placeholder(__('No end'))->sortable(),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->state(fn (Invitation $record) => $record->problem() === null ? __('Open') : ($record->revoked_at ? __('Withdrawn') : ($record->expires_at?->isPast() ? __('Expired') : __('Used up'))))
                    ->color(fn (Invitation $record) => $record->problem() === null ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('open')->label(__('Open'))->queries(
                    true: fn (Builder $q) => Invitation::applyUsable($q),
                    false: fn (Builder $q) => $q->whereNot(fn (Builder $q) => Invitation::applyUsable($q)),
                ),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('newCode')->label(__('New link'))->icon(Heroicon::OutlinedKey)
                        ->modalDescription(__('The old link stops working, and you get a new one to copy.'))
                        ->schema([
                            TextInput::make('send_to')->label(__('Mail the link to'))->email()->maxLength(190)->helperText(__('Optional.')),
                            Textarea::make('greeting')->label(__('A few words from you'))->rows(2)->maxLength(1000),
                        ])
                        ->action(function (Invitation $record, array $data) {
                            $code = $record->assignCode();
                            $record->save();
                            Audit::log('invitation.new_code', $record);
                            InvitationResource::showCode($code);
                            InvitationResource::sendLink($code, $data['send_to'] ?? null, $data['greeting'] ?? null);
                        }),
                    Action::make('revoke')->label(__('Withdraw'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (Invitation $record) => $record->revoked_at === null)->requiresConfirmation()
                        ->action(function (Invitation $record) {
                            $record->forceFill(['revoked_at' => now()])->save();
                            Audit::log('invitation.revoked', $record);
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No invitations yet'))
            ->emptyStateDescription(__('Make one, and send its link to the person you invite.'));
    }
}
