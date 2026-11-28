@php($left = $this->remaining())
@php($states = ['available' => __('On the server'), 'partial' => __('Partly on the server'), 'processing' => __('Coming'), 'requested' => __('Requested'), 'none' => ''])
@php($statuses = ['available' => __('On the server'), 'partial' => __('Partly on the server'), 'pending' => __('Waiting for approval'), 'approved' => __('Coming'), 'declined' => __('Declined')])
<x-filament-panels::page>
    <x-filament::section>
        <form wire:submit="find" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
            <div style="flex:1;min-width:14rem">
                <x-filament::input.wrapper>
                    <x-filament::input type="search" wire:model="search" maxlength="100" :placeholder="__('Search for a movie or show')" />
                </x-filament::input.wrapper>
            </div>
            <x-filament::button type="submit" icon="heroicon-m-magnifying-glass">{{ __('Search') }}</x-filament::button>
        </form>
        <p style="opacity:.7;font-size:.85rem;margin-top:.6rem">
            {{ $left === null ? __('No limit on requests.') : __('You can make :count more requests this week.', ['count' => $left]) }}
        </p>
    </x-filament::section>

    @if ($results !== [])
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(9rem,1fr));gap:1rem">
            @foreach ($results as $r)
                <div>
                    @if ($r['poster'])
                        <img src="{{ route('filament.app.tmdb', ['file' => ltrim($r['poster'], '/')]) }}" alt="" loading="lazy" onerror="this.onerror=null;this.src='data:image/gif;base64,R0lGODlhAQABAAAAACw='" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.6rem;background:rgba(127,127,127,.18)">
                    @else
                        <div style="width:100%;aspect-ratio:2/3;border-radius:.6rem;background:rgba(127,127,127,.18)"></div>
                    @endif
                    <div style="font-weight:600;font-size:.9rem;margin-top:.4rem">{{ $r['title'] }} @if ($r['year'])<span style="opacity:.6;font-weight:400">({{ $r['year'] }})</span>@endif</div>
                    <div style="margin-top:.35rem">
                        @if ($r['state'] === 'none')
                            {{ ($this->requestAction)(['type' => $r['type'], 'tmdb' => $r['tmdb']]) }}
                        @else
                            <x-filament::badge :color="$r['state'] === 'available' ? 'success' : 'warning'">{{ $states[$r['state']] }}</x-filament::badge>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <x-filament::section :heading="__('My requests')">
        @forelse ($this->mine() as $m)
            <div style="display:flex;justify-content:space-between;gap:1rem;padding:.55rem 0;border-top:1px solid rgba(127,127,127,.2)">
                <span>{{ $m['title'] }}</span>
                <x-filament::badge :color="match ($m['status']) { 'available' => 'success', 'declined' => 'danger', default => 'warning' }">{{ $statuses[$m['status']] ?? $m['status'] }}</x-filament::badge>
            </div>
        @empty
            <p style="opacity:.75">{{ __('No requests yet.') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament-actions::modals />
</x-filament-panels::page>
