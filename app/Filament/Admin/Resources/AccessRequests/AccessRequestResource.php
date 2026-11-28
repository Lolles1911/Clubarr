<?php

namespace App\Filament\Admin\Resources\AccessRequests;

use App\Filament\Admin\Resources\AccessRequests\Pages\ListAccessRequests;
use App\Models\AccessRequest;
use App\Models\Invitation;
use App\Services\Applications;
use App\Services\SiteSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** Applications from /apply: approve (an invitation is mailed) or decline. */
class AccessRequestResource extends Resource
{
    protected static ?string $model = AccessRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHandRaised;

    protected static ?int $navigationSort = 24;

    public static function getModelLabel(): string
    {
        return __('application');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Applications');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Applications::enabled() || AccessRequest::exists();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = AccessRequest::where('status', 'pending')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('medium')->description(fn (AccessRequest $r) => $r->email)->searchable(['name', 'email']),
                TextColumn::make('message')->label(__('Message'))->wrap()->limit(160)->placeholder('—')->visibleFrom('md'),
                TextColumn::make('created_at')->label(__('Applied'))->since()->visibleFrom('sm'),
                TextColumn::make('status')->label(__('Status'))->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'approved' => __('Invited'), 'declined' => __('Declined'), default => __('Waiting')
                    })
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success', 'declined' => 'gray', default => 'warning'
                    }),
            ])
            ->recordActions([
                Action::make('approve')->label(__('Invite'))->icon(Heroicon::OutlinedCheck)->color('success')->button()->size('sm')
                    ->visible(fn (AccessRequest $r) => $r->status === 'pending')
                    ->modalDescription(__('They get an invitation by mail (one person, valid 14 days) with the template\'s servers, libraries and limits.'))
                    ->fillForm(fn () => ['template' => app(SiteSettings::class)->get('apply_template_id')])
                    ->schema([
                        Select::make('template')->label(__('Template'))->required()
                            ->options(fn () => Invitation::where('is_template', true)->orderBy('label')->pluck('label', 'id')->map(fn ($l) => $l ?: __('Template'))->all()),
                    ])
                    ->action(function (AccessRequest $r, array $data) {
                        $template = Invitation::where('is_template', true)->find($data['template']);
                        try {
                            if ($template === null) {
                                throw new RuntimeException(__('Choose a template.'));
                            }
                            app(Applications::class)->approve($r, $template);
                            Notification::make()->title(__('Invited :name.', ['name' => $r->name]))->success()->send();
                        } catch (RuntimeException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
                Action::make('decline')->label(__('Decline'))->icon(Heroicon::OutlinedXMark)->color('gray')->size('sm')
                    ->visible(fn (AccessRequest $r) => $r->status === 'pending')
                    ->schema([Textarea::make('reason')->label(__('A few words to them (optional, by mail)'))->rows(2)->maxLength(1000)])
                    ->action(fn (AccessRequest $r, array $data) => app(Applications::class)->decline($r, $data['reason'] ?? null)),
                DeleteAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('No applications'));
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessRequests::route('/')];
    }
}
