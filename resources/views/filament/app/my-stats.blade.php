@php($n = $this->numbers())
@php($y = $n['year'])
@php($m = $n['month'])
<x-filament-panels::page>
    <section style="padding:1.4rem 1.5rem;border-radius:1rem;background:linear-gradient(135deg,color-mix(in oklab,var(--primary-600) 35%,transparent),color-mix(in oklab,var(--primary-400) 8%,transparent))">
        <div style="font-size:.85rem;opacity:.8">{{ __('Your year') }}</div>
        @if ($y['plays'] === 0)
            <div style="font-size:1.4rem;font-weight:800">{{ __('Nothing watched yet. Time for popcorn?') }}</div>
        @else
            <div style="font-size:clamp(1.6rem,5vw,2.4rem);font-weight:800;line-height:1.1">{{ __(':hours hours', ['hours' => $y['hours']]) }}</div>
            <div style="opacity:.85;margin-top:.3rem">{{ __(':plays plays since :date', ['plays' => $y['plays'], 'date' => \Illuminate\Support\Carbon::createFromTimestamp($y['first'])->translatedFormat('j F Y')]) }}</div>
            @php($day = array_search(max($y['weekdays']), $y['weekdays'], true))
            @php($hour = array_search(max($y['hours_of_day']), $y['hours_of_day'], true))
            <div style="display:flex;flex-wrap:wrap;gap:.5rem 1.5rem;margin-top:.9rem;font-size:.9rem">
                <span>{{ __('Favourite day: :day', ['day' => \Illuminate\Support\Carbon::now()->startOfWeek()->addDays($day - 1)->translatedFormat('l')]) }}</span>
                <span>{{ __('Favourite time: around :hour', ['hour' => sprintf('%02d:00', $hour)]) }}</span>
                @if ($y['platforms'] !== [])<span>{{ __('Mostly on :device', ['device' => array_key_first($y['platforms'])]) }}</span>@endif
            </div>
            @if ($y['titles'] !== [])
                <div style="margin-top:1rem">
                    <div style="font-weight:700;margin-bottom:.3rem">{{ __('Your top 5') }}</div>
                    <ol style="margin:0;padding-left:1.2rem;line-height:1.7">
                        @foreach (array_slice($y['titles'], 0, 5) as $t)<li>{{ $t['title'] }} <span style="opacity:.7">· {{ __(':hours h', ['hours' => $t['hours']]) }}</span></li>@endforeach
                    </ol>
                </div>
            @endif
        @endif
    </section>

    <x-filament::section :heading="__('Last 30 days: :hours hours', ['hours' => $m['hours']])">
        <x-bars :values="$m['days']" :labels="array_map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('j/n'), array_keys($m['days']))" :every="5" />
    </x-filament::section>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr));gap:1rem">
        <x-filament::section :heading="__('When you watch')">
            <x-bars :values="$y['hours_of_day']" :every="3" height="5rem" />
        </x-filament::section>
        <x-filament::section :heading="__('Most watched, 30 days')">
            @forelse ($m['titles'] as $t)
                <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0"><span>{{ $t['title'] }}</span><span style="opacity:.7">{{ __(':hours h', ['hours' => $t['hours']]) }}</span></div>
            @empty
                <p style="font-size:.85rem;opacity:.7">{{ __('Nothing watched yet.') }}</p>
            @endforelse
        </x-filament::section>
    </div>
</x-filament-panels::page>
