<?php

namespace App\Filament\App\Pages;

use App\Models\Invitation;
use App\Models\User;
use App\Services\MemberInvites;
use App\Services\SiteSettings;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Locked;
use RuntimeException;

/** Members invite friends, within the admin's monthly number and template. */
class InviteFriend extends Page
{
    use WithRateLimiting;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-plus';

    protected static ?int $navigationSort = 30;

    protected string $view = 'filament.app.invite-friend';

    protected static ?string $slug = 'invite';

    /** The link just made, shown once (Copy, Share, QR). */
    #[Locked]
    public ?string $shareLink = null;

    public function closeShare(): void
    {
        $this->shareLink = null;
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Invite a friend');
    }

    #[Unexposed]
    public function getTitle(): string|Htmlable
    {
        return __('Invite a friend');
    }

    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && MemberInvites::allowed($user);
    }

    #[Unexposed]
    public function member(): User
    {
        /** @var User $user */
        $user = Filament::auth()->user();

        return $user;
    }

    #[Unexposed]
    public function remaining(): int
    {
        return MemberInvites::remaining($this->member());
    }

    /** @return Collection<int, Invitation> */
    #[Unexposed]
    public function mine(): Collection
    {
        return Invitation::where('created_by', $this->member()->id)->where('by_member', true)->with('accounts.user')->latest()->limit(20)->get();
    }

    public function inviteAction(): Action
    {
        return Action::make('invite')->label(__('Make an invitation'))->icon('heroicon-m-user-plus')->size('lg')
            ->disabled(fn () => $this->remaining() < 1)
            ->schema([
                TextInput::make('email')->label(__('Your friend\'s email'))->email()->maxLength(190)
                    ->helperText(app(SiteSettings::class)->get('mail_enabled') === '1' ? __('Optional: we mail them the link. Or share it yourself afterwards.') : __('Share the link yourself afterwards.'))
                    ->visible(fn () => app(SiteSettings::class)->get('mail_enabled') === '1'),
                Textarea::make('greeting')->label(__('A few words from you'))->rows(3)->maxLength(1000)
                    ->placeholder(__('e.g. "Here is the access I told you about!"')),
            ])
            ->action(function (array $data) {
                try {
                    $this->rateLimit(5, 60, 'invite');
                    [, $code, $sent] = app(MemberInvites::class)->invite($this->member(), $data['email'] ?? null, $data['greeting'] ?? null);
                } catch (TooManyRequestsException) {
                    Notification::make()->title(__('Too many attempts. Try again in a minute.'))->danger()->send();

                    return;
                } catch (RuntimeException $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }
                $this->shareLink = route('filament.app.join', ['code' => $code]);
                MemberInvites::needsApproval()
                    ? Notification::make()->title(__('Here is the invitation link'))->body(__('An admin approves it first; then the link works, and it is mailed if you gave an email.'))->warning()->persistent()->send()
                    : Notification::make()->title(__('Here is the invitation link'))->success()->send();
                foreach ($sent as $result) {
                    Notification::make()->title($result['text'])->{$result['ok'] ? 'success' : 'warning'}()->send();
                }
            });
    }
}
