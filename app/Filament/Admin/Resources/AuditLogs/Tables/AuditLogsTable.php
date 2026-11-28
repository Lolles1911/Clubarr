<?php

namespace App\Filament\Admin\Resources\AuditLogs\Tables;

use App\Models\AuditLog;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('When'))->dateTime('j M Y, H:i:s')->sortable(),
                TextColumn::make('actor.email')->visibleFrom('sm')->label(__('Who'))->placeholder(__('Clubarr'))->searchable(),
                TextColumn::make('action')->label(__('What'))->badge()->searchable(),
                TextColumn::make('meta')->visibleFrom('md')->label(__('Details'))->wrap()->limit(120)
                    ->state(fn (AuditLog $record) => collect($record->meta ?? [])->map(fn ($v, $k) => $k.': '.(is_array($v) ? implode(', ', $v) : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v)))->implode(' · ')),
                TextColumn::make('ip')->visibleFrom('lg')->label('IP')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('action')->label(__('What'))
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('action')->pluck('action', 'action')->all()),
            ]);
    }
}
