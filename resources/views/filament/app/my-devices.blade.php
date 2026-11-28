@php($streams = $this->streams())
@php($devices = $this->devices())
@php($line = 'display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.65rem 0;border-top:1px solid rgba(127,127,127,.2)')
<x-filament-panels::page>
    <x-filament::section :heading="__('Playing now')">
        @forelse ($streams as $s)
            <div style="{{ $line }}">
                <div>
                    <div style="font-weight:600">{{ ($s['show_title'] ?? '') !== '' ? $s['show_title'].' · '.($s['media_title'] ?? '') : ($s['media_title'] ?? '') }}</div>
                    <div style="opacity:.7;font-size:.85rem">{{ trim(($s['device'] ?? '').' · '.($s['player'] ?? ''), ' ·') }} · {{ ucfirst((string) ($s['server_type'] ?? '')) }}</div>
                </div>
                {{ ($this->stopAction)(['id' => (string) ($s['id'] ?? '')]) }}
            </div>
        @empty
            <p style="opacity:.75">{{ __('Nothing is playing on your users right now.') }}</p>
        @endforelse
        @if ($this->member()->max_streams)
            <p style="opacity:.7;font-size:.85rem;margin-top:.75rem">{{ __('Your account allows :count streams at the same time.', ['count' => $this->member()->max_streams]) }}</p>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('Devices, last 30 days')">
        @forelse ($devices as $d)
            <div style="{{ $line }}">
                <div>
                    <div style="font-weight:600">{{ $d['device'] }}</div>
                    <div style="opacity:.7;font-size:.85rem">{{ trim($d['player'].' · '.$d['server'], ' ·') }}</div>
                </div>
                <div style="text-align:right;opacity:.75;font-size:.85rem">{{ \Illuminate\Support\Carbon::createFromTimestamp($d['last'])->diffForHumans() }}<br>{{ $d['plays'] === 1 ? __('1 play') : __(':count plays', ['count' => $d['plays']]) }}</div>
            </div>
        @empty
            <p style="opacity:.75">{{ __('No plays in the last 30 days.') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
