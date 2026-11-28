<?php

namespace App\Filament\Admin\Resources\Invitations\Tables;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Integrations\Discord;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit;
use App\Services\InvitationMaker;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InvitationsTable
{
    /** Who to send a link to, and the words with it. */
    public static function sendFields(bool $withNote): array
    {
        return array_values(array_filter([
            $withNote ? TextInput::make('label')->label(__('Note'))->maxLength(100)->helperText(__('Only you see it.')) : null,
            $withNote ? DateTimePicker::make('expires_at')->label(__('Link valid until'))->seconds(false)->helperText(__('Empty: no end.')) : null,
            TextInput::make('send_to')->label(__('Mail the link to'))->email()->maxLength(190)->helperText(__('Optional.')),
            Discord::fromSettings() !== null ? TextInput::make('send_discord')->label(__('Send it on Discord to (user ID)'))->maxLength(22)->regex('/^\d{15,22}$/')->helperText(__('Optional.')) : null,
            Textarea::make('greeting')->label(__('A few words from you'))->rows(3)->maxLength(1000),
        ]));
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['mediaServers', 'accounts.user', 'creator']))
            ->columns([
                TextColumn::make('label')->label(__('Note'))->searchable()->placeholder('—')
                    ->description(fn (Invitation $record) => $record->is_template ? __('Template') : $record->code_hint.'…'.($record->by_member && $record->creator ? ' · '.__('from :name', ['name' => $record->creator->name]) : '')),
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
                    ->state(fn (Invitation $record) => $record->is_template ? __('Template') : ($record->problem() === null ? __('Open') : ($record->revoked_at ? __('Withdrawn') : ($record->expires_at?->isPast() ? __('Expired') : __('Used up')))))
                    ->color(fn (Invitation $record) => $record->problem() === null ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('open')->label(__('Open'))->queries(
                    true: fn (Builder $q) => Invitation::applyUsable($q),
                    false: fn (Builder $q) => $q->whereNot(fn (Builder $q) => Invitation::applyUsable($q)),
                ),
            ])
            ->recordActions([
                Action::make('useTemplate')->label(__('Make an invitation'))->icon(Heroicon::OutlinedPlus)->button()->size('sm')
                    ->visible(fn (Invitation $record) => $record->is_template)
                    ->modalHeading(fn (Invitation $record) => __('New invitation from ":template"', ['template' => $record->label ?: __('Template')]))
                    ->fillForm(fn (Invitation $record) => ['greeting' => $record->greeting, 'expires_at' => now()->addDays(7)->startOfHour()])
                    ->schema(self::sendFields(withNote: true))
                    ->action(function (Invitation $record, array $data) {
                        [$settings, $pivot] = app(InvitationMaker::class)->setupOf($record);
                        $user = Auth::user();
                        [$invitation, $code] = app(InvitationMaker::class)->create(
                            ['label' => $data['label'] ?? null, 'expires_at' => $data['expires_at'] ?? null, 'greeting' => $data['greeting'] ?? null] + $settings,
                            $pivot, $user instanceof User ? $user : null);
                        InvitationResource::showCode($code);
                        InvitationResource::sendLink($invitation, $code, $data['send_to'] ?? null, $data['send_discord'] ?? null);
                    }),
                ActionGroup::make([
                    Action::make('newCode')->label(__('New link'))->icon(Heroicon::OutlinedKey)
                        ->visible(fn (Invitation $record) => ! $record->is_template)
                        ->modalDescription(__('The old link stops working, and you get a new one to copy.'))
                        ->schema(self::sendFields(withNote: false))
                        ->fillForm(fn (Invitation $record) => ['greeting' => $record->greeting])
                        ->action(function (Invitation $record, array $data) {
                            $code = $record->assignCode();
                            $record->forceFill(['greeting' => $data['greeting'] ?? null, 'reminder_link' => null, 'reminded_at' => null])->save();
                            Audit::log('invitation.new_code', $record);
                            InvitationResource::showCode($code);
                            InvitationResource::sendLink($record, $code, $data['send_to'] ?? null, $data['send_discord'] ?? null);
                        }),
                    Action::make('revoke')->label(__('Withdraw'))->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                        ->visible(fn (Invitation $record) => $record->revoked_at === null && ! $record->is_template)->requiresConfirmation()
                        ->action(function (Invitation $record) {
                            $record->forceFill(['revoked_at' => now()])->save();
                            Audit::log('invitation.revoked', $record);
                        }),
                    EditAction::make(),
                    DeleteAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('Nothing here yet'))
            ->emptyStateDescription(__('Make one, and send its link to the person you invite.'));
    }
}
