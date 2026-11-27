<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
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
                Toggle::make('is_admin')->label(__('Admin'))->default(true)
                    ->disabled(fn (?User $record) => $record !== null && $record->is(Auth::user()))
                    ->helperText(__('Admins sign in at /admin, with two-factor sign-in.')),
                DateTimePicker::make('access_expires_at')->label(__('Access ends'))->seconds(false)
                    ->helperText(__('Empty: no end. Changing it does not pause or resume users by itself; use the actions in the list.'))
                    ->hiddenOn('create'),
            ]),
        ]);
    }
}
