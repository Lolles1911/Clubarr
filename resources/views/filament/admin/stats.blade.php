<x-filament-panels::page>
    @if (! $this->enabled())
        <x-filament::section>{{ __('Set up Tracearr or Tautulli under Settings → Integrations to see what is watched.') }}</x-filament::section>
    @else
        @php($s = $this->summary())
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            @foreach ([7 => __('7 days'), 30 => __('30 days'), 90 => __('90 days'), 365 => __('Year')] as $d => $label)
                <x-filament::button size="sm" :color="$days === $d ? 'primary' : 'gray'" wire:click="$set('days', {{ $d }})">{{ $label }}</x-filament::button>
            @endforeach
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:1rem">
            @foreach ([[__('Hours watched'), $s['hours']], [__('Plays'), $s['plays']], [__('Members who watched'), count(array_filter($s['people']))]] as [$label, $value])
                <x-filament::section :compact="true"><div style="opacity:.7;font-size:.8rem">{{ $label }}</div><div style="font-size:1.6rem;font-weight:700">{{ $value }}</div></x-filament::section>
            @endforeach
        </div>
        <x-filament::section :heading="__('Hours per day')">
            <x-bars :values="$s['days']" :labels="array_map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('j/n'), array_keys($s['days']))" :every="max(1, intdiv(count($s['days']), 10))" />
        </x-filament::section>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,22rem),1fr));gap:1rem">
            <x-filament::section :heading="__('When (hour of the day)')">
                <x-bars :values="$s['hours_of_day']" :every="3" height="5rem" />
            </x-filament::section>
            <x-filament::section :heading="__('Devices')">
                @foreach ($s['platforms'] as $platform => $count)
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0"><span>{{ $platform }}</span><span style="opacity:.7">{{ __(':count plays', ['count' => $count]) }}</span></div>
                @endforeach
            </x-filament::section>
            <x-filament::section :heading="__('Members who watched most')">
                @foreach ($s['people'] as $name => $hours)
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0"><span>{{ $name }}</span><span style="opacity:.7">{{ __(':hours h', ['hours' => $hours]) }}</span></div>
                @endforeach
            </x-filament::section>
            <x-filament::section :heading="__('Most watched')">
                @foreach ($s['titles'] as $i => $t)
                    <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0"><span>{{ $i + 1 }}. {{ $t['title'] }}</span><span style="opacity:.7">{{ __(':hours h', ['hours' => $t['hours']]) }}</span></div>
                @endforeach
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
