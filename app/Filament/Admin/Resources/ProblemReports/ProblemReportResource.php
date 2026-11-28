<?php

namespace App\Filament\Admin\Resources\ProblemReports;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\ProblemReports\Pages\ListProblemReports;
use App\Models\ProblemReport;
use App\Services\Audit;
use App\Services\MemberRequests;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** "Something doesn't work" from members: answer (by mail) and close. */
class ProblemReportResource extends Resource
{
    protected static ?string $model = ProblemReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?int $navigationSort = 30;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('problem');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Problems');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ProblemReport::where('status', 'open')->count();

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
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'mediaServer']))
            ->columns([
                TextColumn::make('subject')->label(__('Problem'))->weight('medium')->wrap()
                    ->description(fn (ProblemReport $r) => mb_strimwidth($r->body, 0, 160, '…')),
                TextColumn::make('user.name')->label(__('Member'))->description(fn (ProblemReport $r) => $r->mediaServer?->name),
                TextColumn::make('created_at')->label(__('Reported'))->since()->visibleFrom('sm'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state, ProblemReport $r) => $state === 'closed' ? __('Closed') : ($r->replied_at ? __('Answered') : __('Open')))
                    ->color(fn (string $state, ProblemReport $r) => $state === 'closed' ? 'gray' : ($r->replied_at ? 'info' : 'warning')),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(['open' => __('Open'), 'closed' => __('Closed')])->default('open'),
            ])
            ->recordActions([
                Action::make('reply')->label(__('Answer'))->icon(Heroicon::OutlinedChatBubbleLeftRight)->button()->size('sm')
                    ->modalHeading(fn (ProblemReport $r) => $r->subject)
                    ->modalDescription(fn (ProblemReport $r) => $r->user->name.': '.$r->body)
                    ->fillForm(fn (ProblemReport $r) => ['reply' => $r->reply, 'close' => true])
                    ->schema([
                        Textarea::make('reply')->label(__('Your answer'))->required()->rows(4)->maxLength(4000)->helperText(__('Mailed to the member, and shown on their Help page.')),
                        Toggle::make('close')->label(__('Close it')),
                    ])
                    ->action(fn (ProblemReport $r, array $data) => app(MemberRequests::class)->reply($r, (string) $data['reply'], (bool) $data['close'])),
                Action::make('close')->label(__('Close'))->icon(Heroicon::OutlinedCheck)->color('gray')->size('sm')
                    ->visible(fn (ProblemReport $r) => $r->status === 'open')
                    ->action(function (ProblemReport $r) {
                        $r->forceFill(['status' => 'closed'])->save();
                        Audit::log('problem.closed', $r);
                    }),
            ])
            ->emptyStateHeading(__('No problems reported'));
    }

    public static function getPages(): array
    {
        return ['index' => ListProblemReports::route('/')];
    }
}
