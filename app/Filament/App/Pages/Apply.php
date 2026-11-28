<?php

namespace App\Filament\App\Pages;

use App\Services\Applications;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * /apply: ask to join. An admin decides; approved, an invitation comes by
 * mail. Says the same whether the email is known or not, so it can't be
 * used to find out who is a member.
 *
 * @property-read Schema $form
 */
class Apply extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public bool $done = false;

    #[Unexposed]
    public function mount(): void
    {
        abort_unless(Applications::enabled(), 404);
        $this->form->fill();
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Apply to join');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        return $this->done ? null : __('Tell us who you are. If we say yes, you get an invitation by mail.');
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('name')->label(__('Your name'))->required()->maxLength(100),
            TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190),
            ...self::packageFields(),
            ...collect(Applications::questions())->map(fn (string $q, int $i) => Textarea::make('answers.'.$i)->label($q)->required()->rows(2)->maxLength(1000))->all(),
            Textarea::make('message')->label(Applications::questions() === [] ? __('How do you know us? (optional)') : __('Anything else? (optional)'))->rows(3)->maxLength(1000),
            // Left empty by people; filled by bots.
            TextInput::make('website')->label('Website')->extraAttributes(['style' => 'position:absolute;left:-10000px', 'tabindex' => '-1', 'autocomplete' => 'off'])->hiddenLabel(),
        ]);
    }

    /** The admin's packages to choose from, and the chosen one's servers (all ticked; they may leave some out). */
    private static function packageFields(): array
    {
        $packages = Applications::packages();
        if ($packages === []) {
            return [];
        }

        return [
            Radio::make('package')->label(__('What would you like?'))->required()->live()
                ->options(array_map(fn ($p) => $p['label'], $packages))
                ->descriptions(array_map(fn ($p) => $p['description'], $packages))
                ->afterStateUpdated(fn ($state, Set $set) => $set('servers', isset($packages[(int) $state]) ? $packages[(int) $state]['template']->mediaServers->pluck('id')->map(fn ($id) => (string) $id)->all() : [])),
            CheckboxList::make('servers')->label(__('Servers'))->required()
                ->visible(fn (Get $get) => filled($get('package')) && count($packages[(int) $get('package')]['template']->mediaServers ?? []) > 1)
                ->options(fn (Get $get) => filled($get('package')) ? $packages[(int) $get('package')]['template']->mediaServers->mapWithKeys(fn ($s) => [$s->id => $s->name.' ('.$s->type->label().')'])->all() : [])
                ->helperText(__('Leave out the ones you don\'t need.')),
        ];
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        if ($this->done) {
            return $schema->components([Text::make(__('Thanks! We will look at it and write to you by mail.'))]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('join')
                ->footer([Actions::make([Action::make('join')->label(__('Send'))->submit('join')])->fullWidth()]),
        ]);
    }

    public function join(): void
    {
        abort_unless(Applications::enabled(), 404);
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException) {
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

            return;
        }
        $data = $this->form->getState();
        if (blank($data['website'] ?? null)) {
            app(Applications::class)->apply((string) $data['name'], (string) $data['email'], $data['message'] ?? null, (array) ($data['answers'] ?? []),
                filled($data['package'] ?? null) ? (int) $data['package'] : null, array_map('intval', (array) ($data['servers'] ?? [])));
        }
        $this->done = true;
    }
}
