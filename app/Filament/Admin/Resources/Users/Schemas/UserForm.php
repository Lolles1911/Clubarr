<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use App\Support\AdminRole;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->label(__('Name'))->required()->maxLength(100),
                TextInput::make('email')->label(__('Email'))->email()->required()->maxLength(190)->unique(User::class, 'email', ignoreRecord: true),
                TextInput::make('password')->label(__('Password'))->password()->revealable()
                    ->rule(Password::min(12)->letters()->numbers())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->visibleOn('create')
                    ->helperText(__('Only for admins you add here. Members join with an invitation.')),
                Toggle::make('is_admin')->label(__('Admin'))->default(true)->live()
                    ->disabled(fn (?User $record) => $record !== null && $record->is(Auth::user()))
                    ->visible(fn () => AdminRole::full())
                    ->helperText(__('Admins sign in at /admin, with two-factor sign-in.')),
                Select::make('admin_role')->label(__('Admin role'))->options(AdminRole::options())->selectablePlaceholder(false)
                    ->formatStateUsing(fn (?string $state) => $state ?? 'admin')
                    ->visible(fn (Get $get) => AdminRole::full() && (bool) $get('is_admin'))
                    ->disabled(fn (?User $record) => $record !== null && $record->is(Auth::user()))
                    ->helperText(__('Moderators handle members, invitations, requests and messages, but not settings, servers, groups, API keys or the log.')),
                DateTimePicker::make('access_expires_at')->label(__('Access ends'))->seconds(false)
                    ->helperText(__('Empty: no end. Changing it does not pause or resume users by itself; use the actions in the list.'))
                    ->hiddenOn('create'),
                TextInput::make('max_streams')->label(__('Streams at the same time'))->numeric()->minValue(1)->maxValue(50)
                    ->helperText(__('Needs Tracearr. Empty: no limit.'))->hiddenOn('create'),
                Toggle::make('seerr_access')->label(__('May request (Seerr)'))->live()->hiddenOn('create')
                    ->helperText(__('Gives a user in Overseerr/Jellyseerr and the Requests page.')),
                TextInput::make('request_limit')->label(__('Requests per week'))->numeric()->minValue(0)->maxValue(1000)
                    ->helperText(__('Needs Seerr. Empty: no limit.'))->hiddenOn('create')->visible(fn (Get $get) => (bool) $get('seerr_access')),
            ]),
            Section::make(__('Groups and notes'))->hiddenOn('create')->schema([
                Select::make('groups')->label(__('Groups'))->multiple()->relationship('groups', 'name')->preload()
                    ->visible(fn () => AdminRole::full())
                    ->helperText(__('The groups\' rules apply when you save.')),
                Textarea::make('admin_note')->label(__('Private note'))->rows(3)->maxLength(5000)
                    ->helperText(__('Only admins see it.')),
            ]),
        ]);
    }
}
