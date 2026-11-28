<?php

namespace App\Filament\App\Pages;

use App\Models\ProblemReport;
use App\Models\User;
use App\Models\WizardStep;
use App\Services\MemberRequests;
use App\Services\WizardSteps;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/** "Something doesn't work": members tell the admins, and see the answers. */
class Help extends Page
{
    use WithRateLimiting;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.app.help';

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Help');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Help');
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    /** @return Collection<int, ProblemReport> */
    #[Unexposed]
    public function reports(): Collection
    {
        return ProblemReport::where('user_id', $this->member()->id)->with('mediaServer')->latest()->limit(20)->get();
    }

    /** @return \Illuminate\Support\Collection<int, WizardStep> the guides (the wizard's steps for after joining) */
    #[Unexposed]
    public function guides(): \Illuminate\Support\Collection
    {
        return app(WizardSteps::class)->forMember($this->member());
    }

    public function reportAction(): Action
    {
        return Action::make('report')->label(__('Something doesn\'t work'))->icon('heroicon-m-exclamation-triangle')->size('lg')
            ->schema([
                Select::make('server')->label(__('Server'))->placeholder(__('Not about one server'))
                    ->options(fn () => $this->member()->serverAccounts()->with('mediaServer')->get()->mapWithKeys(fn ($a) => [$a->media_server_id => $a->mediaServer->name])->all()),
                TextInput::make('subject')->label(__('What is it about?'))->required()->maxLength(150)->placeholder(__('e.g. "Dune won\'t play" or "No Danish subtitles on The Bear"')),
                Textarea::make('body')->label(__('What happens?'))->required()->rows(4)->maxLength(4000)
                    ->helperText(__('Which app or device, and what you see. The more, the easier to fix.')),
            ])
            ->action(function (array $data) {
                try {
                    $this->rateLimit(5, 3600, 'report');
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('You have reported a lot this hour. Try again later.'))->danger()->send();

                    return;
                }
                app(MemberRequests::class)->report($this->member(), filled($data['server'] ?? null) ? (int) $data['server'] : null, (string) $data['subject'], (string) $data['body']);
                Notification::make()->title(__('Thanks! We have got it and will answer here (and by mail).'))->success()->send();
            });
    }
}
