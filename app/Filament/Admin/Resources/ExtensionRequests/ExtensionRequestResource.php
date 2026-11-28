<?php

namespace App\Filament\Admin\Resources\ExtensionRequests;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\ExtensionRequests\Pages\ListExtensionRequests;
use App\Models\ExtensionRequest;
use App\Services\MemberRequests;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Members asking for more time: approve with a number of days, or decline. */
class ExtensionRequestResource extends Resource
{
    protected static ?string $model = ExtensionRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 20;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('request for more time');
    }

    public static function getPluralModelLabel(): string
    {
        return __('More time');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ExtensionRequest::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user'))
            ->columns([
                TextColumn::make('user.name')->label(__('Member'))->description(fn (ExtensionRequest $r) => $r->note),
                TextColumn::make('user.access_expires_at')->label(__('Access ends'))->date('j M Y')->placeholder(__('No end')),
                TextColumn::make('created_at')->label(__('Asked'))->since()->visibleFrom('sm'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state, ExtensionRequest $r) => match ($state) {
                        'approved' => __('+:days days', ['days' => $r->days]), 'declined' => __('Declined'), default => __('Waiting'),
                    })
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'declined' => 'gray', default => 'warning'
                    }),
            ])
            ->recordActions([
                Action::make('approve')->label(__('Approve'))->icon(Heroicon::OutlinedCheck)->color('success')->button()->size('sm')
                    ->visible(fn (ExtensionRequest $r) => $r->status === 'pending')
                    ->schema([TextInput::make('days')->label(__('More days'))->numeric()->required()->minValue(1)->maxValue(3650)->default(30)])
                    ->action(fn (ExtensionRequest $r, array $data) => app(MemberRequests::class)->approve($r, (int) $data['days'])),
                Action::make('decline')->label(__('Decline'))->icon(Heroicon::OutlinedXMark)->color('gray')->size('sm')
                    ->visible(fn (ExtensionRequest $r) => $r->status === 'pending')
                    ->schema([Textarea::make('reason')->label(__('A few words to them'))->rows(2)->maxLength(1000)])
                    ->action(fn (ExtensionRequest $r, array $data) => app(MemberRequests::class)->decline($r, $data['reason'] ?? null)),
            ])
            ->emptyStateHeading(__('Nobody has asked for more time'));
    }

    public static function getPages(): array
    {
        return ['index' => ListExtensionRequests::route('/')];
    }
}
