@php($w = $this->watching())
<x-filament-widgets::widget>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr));gap:1rem">
        @if ($w['enabled'])
            <x-filament::section :heading="__('Watching now')" icon="heroicon-o-play-circle" :compact="true">
                @forelse ($w['now'] as $s)
                    <div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.85rem;padding:.3rem 0">
                        <span><strong>{{ $s['who'] }}</strong> · {{ $s['title'] }}</span><span style="opacity:.6;white-space:nowrap">{{ $s['player'] }}</span>
                    </div>
                @empty
                    <p style="font-size:.85rem;opacity:.7">{{ __('Nobody right now.') }}</p>
                @endforelse
            </x-filament::section>
            <x-filament::section :heading="__('Most watched, 30 days')" icon="heroicon-o-fire" :compact="true">
                @forelse ($w['top'] as $i => $t)
                    <div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.85rem;padding:.25rem 0">
                        <span>{{ $i + 1 }}. {{ $t['title'] }}</span><span style="opacity:.6;white-space:nowrap">{{ __(':people people', ['people' => $t['people']]) }}</span>
                    </div>
                @empty
                    <p style="font-size:.85rem;opacity:.7">{{ __('Nothing watched yet.') }}</p>
                @endforelse
            </x-filament::section>
        @endif
        <x-filament::section :heading="__('Latest events')" icon="heroicon-o-clipboard-document-list" :compact="true">
            @foreach ($this->events() as $event)
                <div style="display:flex;justify-content:space-between;gap:.75rem;font-size:.82rem;padding:.2rem 0">
                    <span><code style="font-size:.78rem">{{ $event->action }}</code>@if ($event->actor) <span style="opacity:.7">· {{ $event->actor->name }}</span>@endif</span>
                    <span style="opacity:.6;white-space:nowrap">{{ $event->created_at->diffForHumans(short: true) }}</span>
                </div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-widgets::widget>
