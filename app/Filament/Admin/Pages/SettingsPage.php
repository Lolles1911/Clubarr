<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\NavigationGroup;
use App\Services\Audit;
use App\Services\SiteSettings;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;

/**
 * A page of site settings (Notifications, Mail texts, Integrations): one
 * form, Save, and secrets that are never filled in (empty keeps the stored
 * one) and never left in the page after saving.
 *
 * @property-read Schema $form
 */
abstract class SettingsPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = NavigationGroup::System;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** @return list<string> the settings this page edits */
    abstract protected function keys(): array;

    #[Unexposed]
    public function mount(): void
    {
        $s = app(SiteSettings::class);
        $this->form->fill(collect($this->keys())->reject(fn (string $key) => in_array($key, SiteSettings::SECRET, true))
            ->mapWithKeys(fn (string $key) => [$key => $s->get($key)])->all());
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label(__('Save'))->submit('save'), ...$this->footerActions()])]),
        ]);
    }

    /** @return list<Action> more buttons next to Save */
    protected function footerActions(): array
    {
        return [];
    }

    public function save(): void
    {
        $this->store($this->form->getState());
        Notification::make()->title(__('Saved.'))->success()->send();
    }

    /** Saves this page's keys; empty secrets keep the stored one. @param array<string, mixed> $data */
    protected function store(array $data): void
    {
        $data = collect($data)->only($this->keys())
            ->reject(fn ($value, string $key) => in_array($key, SiteSettings::SECRET, true) && blank($value))
            ->map(fn ($value) => is_string($value) ? trim($value) : ($value === null ? '' : (string) $value))->all();
        app(SiteSettings::class)->set($data);
        Audit::log('settings.saved', null, ['keys' => array_keys($data)]);
        foreach (SiteSettings::SECRET as $key) {
            unset($this->data[$key]);
        }
    }
}
