<?php

namespace App\Filament\Admin\Resources\WizardSteps;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\NavigationGroup;
use App\Filament\Admin\Resources\WizardSteps\Pages\ManageWizardSteps;
use App\Models\MediaServer;
use App\Models\WizardBundle;
use App\Models\WizardStep;
use App\Services\Audit;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The wizard (like Wizarr's): steps new members see before joining (on the
 * invitation page) and after (Get started, and Help), per server or for all,
 * standard or in a bundle chosen on an invitation. Drag to order.
 */
class WizardStepResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = WizardStep::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 40;

    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::Servers;

    protected static ?string $slug = 'wizard';

    public static function getModelLabel(): string
    {
        return __('wizard step');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wizard');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            ToggleButtons::make('phase')->label(__('When'))->inline()->required()->default('after')->columnSpanFull()
                ->options(['before' => __('Before joining (invitation page)'), 'after' => __('After joining (Get started, Help)')]),
            Select::make('media_server_id')->label(__('Server'))->placeholder(__('All servers'))
                ->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->all()),
            Select::make('wizard_bundle_id')->label(__('Bundle'))->placeholder(__('Standard steps'))
                ->options(fn () => WizardBundle::orderBy('name')->pluck('name', 'id')->all())
                ->helperText(__('A bundle is chosen on an invitation and replaces the standard steps.')),
            TextInput::make('title')->label(__('Title'))->required()->maxLength(120)->columnSpanFull(),
            MarkdownEditor::make('body')->label(__('Text'))->maxLength(5000)->columnSpanFull()
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList', 'heading'])
                ->helperText(__('Markdown: **bold**, [link](https://...), lists. HTML is shown as text.')),
            TextInput::make('button_label')->label(__('Button (optional)'))->maxLength(60)->placeholder(__('e.g. Download the app')),
            TextInput::make('button_url')->label(__('Button address'))->url()->maxLength(500)->regex('~^https?://~i')->requiredWith('button_label'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->reorderable('position')->defaultSort('position')
            ->emptyStateHeading(__('No steps yet'))
            ->emptyStateDescription(__('Add a step, or "Add ready-made steps" for a server to start from.'))
            ->columns([
                TextColumn::make('title')->label(__('Title'))->weight('medium')->searchable(),
                TextColumn::make('phase')->label(__('When'))->badge()
                    ->formatStateUsing(fn (string $state) => $state === 'before' ? __('Before') : __('After'))
                    ->color(fn (string $state) => $state === 'before' ? 'info' : 'success'),
                TextColumn::make('mediaServer.name')->label(__('Server'))->placeholder(__('All servers')),
                TextColumn::make('bundle.name')->label(__('Bundle'))->placeholder(__('Standard'))->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('phase')->label(__('When'))->options(['before' => __('Before'), 'after' => __('After')]),
                SelectFilter::make('media_server_id')->label(__('Server'))->options(fn () => MediaServer::orderBy('name')->pluck('name', 'id')->all()),
                SelectFilter::make('wizard_bundle_id')->label(__('Bundle'))->options(fn () => WizardBundle::orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                EditAction::make()->after(fn (WizardStep $record) => Audit::log('wizard.step_updated', $record, ['title' => $record->title])),
                DeleteAction::make()->after(fn (WizardStep $record) => Audit::log('wizard.step_deleted', null, ['title' => $record->title])),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWizardSteps::route('/')];
    }
}
