<x-filament-panels::page>
    <div style="display:grid;gap:.75rem">
        @foreach ($this->rows() as $row)
            @php($c = ['bad' => ['danger', 'heroicon-o-x-circle'], 'warn' => ['warning', 'heroicon-o-exclamation-triangle'], 'todo' => ['info', 'heroicon-o-inbox'], 'ok' => ['success', 'heroicon-o-check-circle']][$row['level']])
            <x-filament::section :compact="true" :icon="$c[1]" :icon-color="$c[0]" :heading="$row['title']" :description="$row['detail']">
                @if ($row['url'])
                    <x-slot name="afterHeader">
                        <x-filament::link :href="$row['url']" icon="heroicon-m-arrow-right" icon-position="after">{{ __('Go there') }}</x-filament::link>
                    </x-slot>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
