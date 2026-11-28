<?php

namespace App\Filament\App\Pages;

use App\Services\Applications;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\SimplePage;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Text;
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
            Textarea::make('message')->label(__('How do you know us? (optional)'))->rows(3)->maxLength(1000),
            // Left empty by people; filled by bots.
            TextInput::make('website')->label('Website')->extraAttributes(['style' => 'position:absolute;left:-10000px', 'tabindex' => '-1', 'autocomplete' => 'off'])->hiddenLabel(),
        ]);
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
            app(Applications::class)->apply((string) $data['name'], (string) $data['email'], $data['message'] ?? null);
        }
        $this->done = true;
    }
}
