<?php

namespace App\Filament\App\Pages;

use App\Services\Waitlist;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
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
 * /waitlist: put yourself on the waiting list. Says the same whether the
 * email was on it already or not, so it can't be used to find out who is.
 *
 * @property-read Schema $form
 */
class JoinWaitlist extends SimplePage
{
    use WithRateLimiting;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    #[Locked]
    public bool $done = false;

    #[Unexposed]
    public function mount(): void
    {
        abort_unless(Waitlist::enabled(), 404);
        $this->form->fill();
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Waiting list');
    }

    #[Unexposed]
    public function getSubheading(): string|Htmlable|null
    {
        return $this->done ? null : __('Leave your name and email. When there is room, you get an invitation by mail, valid for :hours hours.', ['hours' => Waitlist::HOURS]);
    }

    #[Unexposed]
    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            TextInput::make('name')->label(__('Your name'))->required()->maxLength(100),
            TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190),
            // Left empty by people; filled by bots.
            TextInput::make('website')->label('Website')->extraAttributes(['style' => 'position:absolute;left:-10000px', 'tabindex' => '-1', 'autocomplete' => 'off'])->hiddenLabel(),
        ]);
    }

    #[Unexposed]
    public function content(Schema $schema): Schema
    {
        if ($this->done) {
            return $schema->components([Text::make(__('You are on the waiting list. We send you an invitation by mail when there is room.'))]);
        }

        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('join')
                ->footer([Actions::make([Action::make('join')->label(__('Join the waiting list'))->submit('join')])->fullWidth()]),
        ]);
    }

    public function join(): void
    {
        abort_unless(Waitlist::enabled(), 404);
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException) {
            Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

            return;
        }
        $data = $this->form->getState();
        if (blank($data['website'] ?? null)) {
            app(Waitlist::class)->join((string) $data['name'], (string) $data['email']);
        }
        $this->done = true;
    }
}
