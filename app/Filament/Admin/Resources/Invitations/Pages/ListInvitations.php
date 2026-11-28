<?php

namespace App\Filament\Admin\Resources\Invitations\Pages;

use App\Filament\Admin\Resources\Invitations\InvitationResource;
use App\Models\Invitation;
use App\Models\User;
use App\Services\Audit;
use App\Services\InvitationMaker;
use App\Services\SiteSettings;
use App\Support\Copy;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class ListInvitations extends ListRecords
{
    protected static string $resource = InvitationResource::class;

    public const MANY_MAX = 100;

    protected function getHeaderActions(): array
    {
        return [
            $this->manyAction(),
            CreateAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'invitations' => Tab::make(__('Invitations'))->modifyQueryUsing(fn (Builder $q) => $q->where('is_template', false)),
            'templates' => Tab::make(__('Templates'))->modifyQueryUsing(fn (Builder $q) => $q->where('is_template', true))
                ->badge(fn () => Invitation::where('is_template', true)->count() ?: null),
        ];
    }

    /** "Many at once": one personal, single-use link per email, from a template's set-up. */
    private function manyAction(): Action
    {
        return Action::make('many')->label(__('Many at once'))->icon('heroicon-m-user-group')->color('gray')
            ->modalDescription(__('Everyone gets their own link, usable once, by mail. The servers, libraries and limits come from the template.'))
            ->schema([
                Select::make('template')->label(__('Template'))->required()
                    ->options(fn () => Invitation::where('is_template', true)->orderBy('label')->pluck('label', 'id')->map(fn ($l) => $l ?: __('Template'))->all())
                    ->helperText(__('No template yet? Make an invitation and tick "Save as a template instead".')),
                Textarea::make('emails')->label(__('Emails'))->required()->rows(6)
                    ->placeholder("anna@example.com\nbo@example.com")
                    ->helperText(__('One per line (or separated by commas), at most :max.', ['max' => self::MANY_MAX])),
                Textarea::make('greeting')->label(__('A few words from you'))->rows(2)->maxLength(1000)->helperText(__('Empty: the template\'s.')),
                DateTimePicker::make('expires_at')->label(__('Links valid until'))->seconds(false)->default(now()->addDays(14)->startOfHour()),
            ])
            ->action(function (array $data) {
                $emails = collect(preg_split('/[\s,;]+/', (string) $data['emails']) ?: [])
                    ->map(fn ($e) => mb_strtolower(trim($e)))->filter(fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL))->unique()->values();
                $template = Invitation::where('is_template', true)->find($data['template']);
                if ($template === null || $emails->isEmpty() || $emails->count() > self::MANY_MAX) {
                    Notification::make()->title(__('Give between 1 and :max valid emails, and a template.', ['max' => self::MANY_MAX]))->danger()->send();

                    return;
                }
                $mail = app(SiteSettings::class)->get('mail_enabled') === '1';
                $maker = app(InvitationMaker::class);
                [$settings, $pivot] = $maker->setupOf($template);
                $user = Auth::user() instanceof User ? Auth::user() : null;
                $lines = [];
                $sent = 0;
                foreach ($emails as $email) {
                    [$invitation, $code] = $maker->create(['label' => $email, 'max_uses' => 1, 'expires_at' => $data['expires_at'] ?? null,
                        'greeting' => filled($data['greeting'] ?? null) ? $data['greeting'] : $settings['greeting']] + $settings, $pivot, $user);
                    $ok = $mail && collect($maker->send($invitation, $code, $email, null, $user))->every(fn ($r) => $r['ok']);
                    $sent += $ok ? 1 : 0;
                    $lines[] = $email.': '.($ok ? __('mailed') : InvitationMaker::link($code));
                }
                Audit::log('invitation.many', $template, ['count' => $emails->count(), 'mailed' => $sent]);
                $text = implode("\n", $lines);
                Notification::make()->title(__(':count invitations made, :sent mailed.', ['count' => $emails->count(), 'sent' => $sent]))
                    ->body(new HtmlString(nl2br(e($sent < $emails->count() ? __('Send the links below yourself; they are shown only this once.')."\n\n".$text : $text))))
                    ->actions($sent < $emails->count() ? [Action::make('copyAll')->label(__('Copy all'))->button()->alpineClickHandler(Copy::handler($text))] : [])
                    ->success()->persistent()->send();
            });
    }
}
