<?php

namespace App\Filament\Admin\Resources\WizardBundles;

use App\Filament\Admin\Concerns\FullAdminsOnly;
use App\Filament\Admin\Resources\WizardBundles\Pages\ManageWizardBundles;
use App\Models\WizardBundle;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Wizard bundles: other sets of steps, chosen on an invitation (e.g. "Family"). Reached from the Wizard page. */
class WizardBundleResource extends Resource
{
    use FullAdminsOnly;

    protected static ?string $model = WizardBundle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'wizard-bundles';

    public static function getModelLabel(): string
    {
        return __('bundle');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Wizard bundles');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([TextInput::make('name')->label(__('Name'))->required()->maxLength(60)->unique(ignoreRecord: true)]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('name')
            ->emptyStateDescription(__('A bundle is a set of steps of its own, e.g. for family. Choose it on an invitation; it replaces the standard steps.'))
            ->columns([
                TextColumn::make('name')->label(__('Name')),
                TextColumn::make('steps_count')->counts('steps')->label(__('Steps')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageWizardBundles::route('/')];
    }
}
