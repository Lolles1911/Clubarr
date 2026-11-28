<?php

namespace App\Filament\Admin\Resources\MemberGroups;

use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\MemberGroups\Pages\ManageMemberGroups;
use App\Models\MemberGroup;
use App\Services\Audit;
use App\Services\GroupRules;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Groups of members ("Family", "Friends") with their own rules. */
class MemberGroupResource extends Resource
{
    protected static ?string $model = MemberGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 15;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Members;

    public static function getModelLabel(): string
    {
        return __('group');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Groups');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(60)->unique(ignoreRecord: true),
                Select::make('color')->label(__('Colour'))->required()->default('gray')
                    ->options(array_combine(MemberGroup::COLORS, array_map('ucfirst', MemberGroup::COLORS))),
            ]),
            Section::make(__('Rules'))->description(__('For everyone in the group. Empty: the group does not set it. In several groups, a member gets the most generous.'))
                ->columns(2)->schema([
                    Toggle::make('no_end')->label(__('Access never ends'))->columnSpanFull(),
                    TextInput::make('max_streams')->label(__('Streams at the same time'))->numeric()->minValue(1)->maxValue(50),
                    TextInput::make('request_limit')->label(__('Requests per week'))->numeric()->minValue(0)->maxValue(1000),
                    Select::make('seerr_access')->label(__('May request (Seerr)'))->placeholder(__('Not set by the group'))
                        ->options(['1' => __('Yes'), '0' => __('No')]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->badge()->color(fn (MemberGroup $g) => $g->color),
                TextColumn::make('users_count')->counts('users')->label(__('Members')),
                IconColumn::make('no_end')->label(__('Never ends'))->boolean(),
                TextColumn::make('max_streams')->label(__('Streams'))->placeholder('—'),
                TextColumn::make('request_limit')->label(__('Requests/week'))->placeholder('—')->visibleFrom('md'),
            ])
            ->recordActions([
                EditAction::make()->after(function (MemberGroup $record) {
                    Audit::log('group.updated', $record, ['name' => $record->name]);
                    app(GroupRules::class)->applyToGroup($record);
                }),
                DeleteAction::make()->after(fn (MemberGroup $record) => Audit::log('group.deleted', null, ['name' => $record->name])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMemberGroups::route('/')];
    }
}
