<?php

namespace App\Filament\App\Concerns;

use App\Models\User;
use App\Services\AccessManager;
use App\Services\Audit;
use App\Services\DiscordSync;
use App\Services\SiteSettings;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/** The member's own settings on My page: the weekly mail and deleting their account. */
trait MemberSettingsActions
{
    abstract public function member(): User;

    public function digestAction(): Action
    {
        return Action::make('digest')
            ->label(fn () => $this->member()->digest ? __('Stop the weekly mail') : __('Get a weekly mail with what is new'))
            ->icon('heroicon-m-envelope')->color('gray')->link()
            ->visible(fn () => app(SiteSettings::class)->get('mail_enabled') === '1')
            ->action(function () {
                $member = $this->member();
                $member->forceFill(['digest' => ! $member->digest, 'digest_sent_at' => $member->digest ? $member->digest_sent_at : now()])->save();
                Audit::log($member->digest ? 'member.digest_on' : 'member.digest_off', $member, [], $member->id);
                Notification::make()->title($member->digest ? __('You will get a mail on Mondays when there is something new.') : __('No more weekly mails.'))->success()->send();
            });
    }

    public function episodesAction(): Action
    {
        return Action::make('episodes')
            ->label(fn () => $this->member()->episode_alerts ? __('Stop messages about new episodes') : __('Tell me about new episodes of my shows'))
            ->icon('heroicon-m-tv')->color('gray')->link()
            ->visible(fn () => app(SiteSettings::class)->get('episode_alerts') !== '0')
            ->action(function () {
                $member = $this->member();
                $member->forceFill(['episode_alerts' => ! $member->episode_alerts])->save();
                Notification::make()->title($member->episode_alerts ? __('You get a message when your shows have new episodes.') : __('No more messages about new episodes.'))->success()->send();
            });
    }

    public function deleteAccountAction(): Action
    {
        return Action::make('deleteAccount')->label(__('Delete my account'))->icon('heroicon-m-trash')->color('danger')->link()
            ->visible(fn () => ! $this->member()->is_admin)
            ->modalHeading(__('Delete your account?'))
            ->modalDescription(__('Your users on the servers are deleted too (Plex: the share is removed), and you can no longer watch. This can\'t be undone.'))
            ->schema([TextInput::make('password')->label(__('Your password'))->password()->required()->currentPassword()])
            ->action(function () {
                $member = $this->member();
                $failed = app(AccessManager::class)->removeAll($member);
                if ($failed !== []) {
                    Notification::make()->title(__('Not everything could be removed right now. Please try again later, or ask us.'))->danger()->send();

                    return null;
                }
                Audit::log('member.deleted_self', null, ['email' => $member->email]);
                app(DiscordSync::class)->sync($member->forceFill(['disabled_at' => now()]));
                Filament::auth()->logout();
                $member->delete();
                session()->invalidate();
                session()->regenerateToken();

                return redirect()->to(Filament::getLoginUrl());
            });
    }
}
