@if ($devices === [])
    <p style="font-size:.875rem;opacity:.7">{{ __('Not shown on this Clubarr.') }}</p>
@else
    <ul style="list-style:none;margin:0;padding:0;font-size:.875rem">
        @foreach ($devices as $device)
            <li style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;padding:.55rem 0;border-top:1px solid rgba(127,127,127,.15)">
                <span style="display:inline-flex;align-items:center;gap:.5rem;font-weight:600">
                    {{ $device['name'] }}
                    @if ($device['current'])
                        <x-filament::badge color="success">{{ __('This device') }}</x-filament::badge>
                    @endif
                </span>
                <span style="opacity:.7">
                    {{ $device['ip'] ?? '' }} · {{ __('active :time', ['time' => $device['active']->diffForHumans()]) }}
                </span>
            </li>
        @endforeach
    </ul>
@endif
