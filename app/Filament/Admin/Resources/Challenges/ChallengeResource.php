<?php

namespace App\Filament\Admin\Resources\Challenges;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\Challenges\Pages\ManageChallenges;
use App\Models\Challenge;
use App\Models\User;
use App\Services\MemberHistory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Challenges ("10 films in December"): members see their progress on their page. */
class ChallengeResource extends Resource
{
    protected static ?string $model = Challenge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 75;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('challenge');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Challenges');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->label(__('Name'))->required()->maxLength(120)->placeholder(__('e.g. 10 films in December'))->columnSpanFull(),
            Select::make('metric')->label(__('What counts'))->required()->options(['movies' => __('Films watched'), 'episodes' => __('Episodes watched'), 'hours' => __('Hours watched')]),
            TextInput::make('target')->label(__('Goal'))->numeric()->required()->minValue(1)->maxValue(10000),
            DateTimePicker::make('starts_at')->label(__('From'))->seconds(false)->required()->default(fn () => now()->startOfDay()),
            DateTimePicker::make('ends_at')->label(__('To'))->seconds(false)->required()->after('starts_at')->default(fn () => now()->addMonth()->endOfDay()),
            Textarea::make('note')->label(__('A few words'))->rows(2)->maxLength(1000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('title')->label(__('Name'))->weight('medium'),
                TextColumn::make('target')->label(__('Goal'))->formatStateUsing(fn (Challenge $record) => $record->target.' '.$record->unit()),
                TextColumn::make('starts_at')->label(__('From'))->date(),
                TextColumn::make('ends_at')->label(__('To'))->date(),
            ])
            ->recordActions([
                Action::make('results')->label(__('Results'))->icon(Heroicon::OutlinedChartBar)->color('gray')
                    ->action(function (Challenge $record) {
                        $history = app(MemberHistory::class);
                        $rows = User::members()->whereNull('disabled_at')->get()->map(fn (User $u) => ['name' => $u->name, 'n' => $record->progress($history->yearPlays($u))])
                            ->filter(fn ($r) => $r['n'] > 0)->sortByDesc('n')->take(15);
                        $done = $rows->filter(fn ($r) => $r['n'] >= $record->target)->count();
                        Notification::make()->title(__(':count reached the goal.', ['count' => $done]))->persistent()
                            ->body(new HtmlString(nl2br(e($rows->map(fn ($r) => $r['name'].': '.$r['n'])->implode("\n") ?: __('Nobody yet.')))))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageChallenges::route('/')];
    }
}
