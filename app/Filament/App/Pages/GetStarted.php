<?php

namespace App\Filament\App\Pages;

use App\Enums\AccountStatus;
use App\Models\ServerAccount;
use App\Models\User;
use App\Models\WizardStep;
use App\Services\SiteSettings;
use App\Services\WizardSteps;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;

/**
 * The last step after joining: per server, the apps to get and how to sign
 * in, and the site's own "getting started" text. Also reachable later.
 */
class GetStarted extends Page
{
    protected string $view = 'filament.app.get-started';

    protected static ?string $slug = 'get-started';

    protected static bool $shouldRegisterNavigation = false;

    /** After joining: only the servers of the invitation just used (null: all of them). */
    #[Locked]
    public ?array $only = null;

    public function mount(): void
    {
        $invitation = (int) request()->query('invitation', 0);
        if ($invitation > 0) {
            /** @var User $user */
            $user = Filament::auth()->user();
            $ids = $user->serverAccounts()->where('invitation_id', $invitation)->pluck('media_server_id')->all();
            $this->only = $ids !== [] ? $ids : null;
        }
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Get started');
    }

    /** @return Collection<int, ServerAccount> */
    #[Unexposed]
    public function accounts(): Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user->serverAccounts()->with('mediaServer')->whereIn('status', [AccountStatus::Active, AccountStatus::Pending])
            ->when($this->only !== null, fn ($q) => $q->whereIn('media_server_id', $this->only))->orderBy('id')->get();
    }

    /** @return \Illuminate\Support\Collection<int, WizardStep> the admin's steps for after joining */
    #[Unexposed]
    public function steps(): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return app(WizardSteps::class)->forMember($user, $this->only);
    }

    #[Unexposed]
    public function helpText(): ?string
    {
        return app(SiteSettings::class)->get('help_text');
    }
}
