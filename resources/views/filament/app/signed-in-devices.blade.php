@if ($devices === [])
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('Not shown on this Clubarr.') }}</p>
@else
    <ul class="divide-y divide-gray-100 dark:divide-white/5 text-sm">
        @foreach ($devices as $device)
            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                <span class="font-medium text-gray-950 dark:text-white">
                    {{ $device['name'] }}
                    @if ($device['current'])
                        <x-filament::badge color="success" class="ms-2 inline-flex">{{ __('This device') }}</x-filament::badge>
                    @endif
                </span>
                <span class="text-gray-500 dark:text-gray-400">
                    {{ $device['ip'] ?? '' }} · {{ __('active :time', ['time' => $device['active']->diffForHumans()]) }}
                </span>
            </li>
        @endforeach
    </ul>
@endif
