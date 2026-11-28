{{-- The first step of an invitation: who invites, the greeting, what you get, and what is waiting. --}}
<div style="display:flex;flex-direction:column;gap:1rem">
    @if (filled($welcome['greeting']))
        <figure style="margin:0;padding:.9rem 1rem;border-radius:.75rem;border-left:4px solid var(--primary-500);background:rgba(127,127,127,.1)">
            <blockquote style="margin:0;font-size:.95rem;line-height:1.55">{!! nl2br(e($welcome['greeting'])) !!}</blockquote>
            @if ($welcome['from'])
                <figcaption style="margin-top:.4rem;font-size:.82rem;opacity:.7">{{ __('— :name', ['name' => $welcome['from']]) }}</figcaption>
            @endif
        </figure>
    @elseif ($welcome['from'])
        <p style="font-size:.95rem">{{ __(':name has invited you.', ['name' => $welcome['from']]) }}</p>
    @endif

    @if (filled($welcome['text']))
        <div style="font-size:.92rem;line-height:1.6">{!! nl2br(e($welcome['text'])) !!}</div>
    @endif

    <div>
        <div style="font-weight:600;font-size:.92rem;margin-bottom:.35rem">{{ __('You get:') }}</div>
        <ul style="display:flex;flex-direction:column;gap:.3rem;font-size:.92rem;margin:0;padding:0;list-style:none">
            @foreach ($welcome['servers'] as $server)
                <li style="display:flex;gap:.5rem;align-items:center"><x-filament::icon icon="heroicon-m-check-circle" style="width:1.1rem;height:1.1rem;color:var(--primary-500)" />{{ $server['name'] }} <span style="opacity:.6">({{ $server['type'] }})</span></li>
            @endforeach
            <li style="display:flex;gap:.5rem;align-items:center"><x-filament::icon icon="heroicon-m-clock" style="width:1.1rem;height:1.1rem;color:var(--primary-500)" />{{ $welcome['days'] ? __('Access for :days days', ['days' => $welcome['days']]) : __('Access until it is ended') }}</li>
        </ul>
    </div>

    @if ($welcome['posters'] !== [])
        <div>
            <div style="font-weight:600;font-size:.92rem;margin-bottom:.45rem">{{ __('New on the server') }}</div>
            <div style="display:flex;gap:.6rem;overflow-x:auto;padding-bottom:.4rem;scroll-snap-type:x mandatory">
                @foreach ($welcome['posters'] as $item)
                    <img src="{{ route('filament.app.join-poster', ['key' => $item['key']]) }}" alt="{{ $item['title'] }}" title="{{ $item['title'] }}" loading="lazy"
                        onerror="this.style.display='none'" style="flex:0 0 5.5rem;width:5.5rem;aspect-ratio:2/3;object-fit:cover;border-radius:.5rem;background:rgba(127,127,127,.18);scroll-snap-align:start">
                @endforeach
            </div>
        </div>
    @endif
</div>
