<?php

namespace App\Filament\Admin\Concerns;

use App\Services\Theme;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * A list as cards (Streaming and Midnight themes, like Wizarr) or as a table
 * (Classic). The same columns either way; on a card each value gets its
 * label in front, since a card has no column headings.
 */
final class CardsOrTable
{
    /** @param list<Column> $columns */
    public static function apply(Table $table, array $columns): Table
    {
        if (! Theme::cards()) {
            return $table->columns($columns);
        }
        foreach ($columns as $i => $column) {
            if ($i > 0 && $column instanceof TextColumn && filled($column->getLabel())) {
                if ($column->isBadge()) {
                    $column->placeholder(null); // an empty badge list says nothing on a card
                } else {
                    $label = $column->getLabel();
                    $empty = $column->getPlaceholder();
                    $column->prefix($label.': ')->color('gray')->size('sm')->placeholder(filled($empty) ? $label.': '.(string) $empty : null);
                }
            }
            $column->visibleFrom(null);
        }

        return $table->columns([Stack::make($columns)->space(2)])->contentGrid(['md' => 2, 'xl' => 3]);
    }
}
