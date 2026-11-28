{{-- My page → My numbers: watching (Tracearr, Tautulli, Jellyfin/Emby or Silo) and the account's status. --}}
<div style="display:grid;gap:1rem">
    @if ($stats !== null)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.75rem">
            @foreach ([[__('Watched, 7 days'), __(':hours h', ['hours' => $stats['hours7']])], [__('Watched, 30 days'), __(':hours h', ['hours' => $stats['hours30']])], [__('Plays, 30 days'), $stats['plays30']], [__('Most watched this year'), $stats['top'] ?? '—']] as [$label, $value])
                <x-filament::section :compact="true">
                    <div style="opacity:.7;font-size:.8rem">{{ $label }}</div>
                    <div style="font-size:1.35rem;font-weight:700;overflow-wrap:anywhere">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>
        @if (\App\Filament\App\Pages\MyStats::canAccess())
            <div><x-filament::link :href="\App\Filament\App\Pages\MyStats::getUrl(panel: 'app')" icon="heroicon-m-chart-bar">{{ __('All my numbers: your year, when you watch') }}</x-filament::link></div>
        @endif
    @else
        <p style="opacity:.75;font-size:.9rem">{{ __('Numbers show up here once you have watched something.') }}</p>
    @endif

    <x-filament::section :heading="__('Your access')" :compact="true">
        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1.25rem;font-size:.9rem">
            <dt style="opacity:.7">{{ __('Access ends') }}</dt><dd>{{ $status['ends'] ?? __('No end') }}</dd>
            <dt style="opacity:.7">{{ __('Servers') }}</dt><dd>{{ $status['servers'] }}</dd>
            @if ($status['groups'] !== [])
                <dt style="opacity:.7">{{ __('Groups') }}</dt><dd>{{ implode(', ', $status['groups']) }}</dd>
            @endif
            @if ($status['requests'] !== null)
                <dt style="opacity:.7">{{ __('Requests') }}</dt><dd>{{ $status['requests'] }}</dd>
            @endif
            @if ($status['invites'] !== null)
                <dt style="opacity:.7">{{ __('Invitations left this month') }}</dt><dd>{{ $status['invites'] }}</dd>
            @endif
        </dl>
    </x-filament::section>
</div>
