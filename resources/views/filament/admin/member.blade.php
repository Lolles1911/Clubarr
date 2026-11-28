@php
    $m = $this->member();
    $inv = $this->invitation();
    $w = $this->watching();
    $row = 'display:flex;justify-content:space-between;gap:1rem;font-size:.88rem;padding:.3rem 0;border-bottom:1px solid rgba(127,127,127,.12)';
@endphp
<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,20rem),1fr));gap:1rem">
        <x-filament::section :heading="__('Member')" icon="heroicon-o-user">
            <div style="{{ $row }}"><span>{{ __('Email') }}</span><span>{{ $m->email }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Status') }}</span><span>{{ $m->disabled_at ? __('Disabled') : ($m->hasActiveAccess() ? __('Active') : __('No access')) }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Access ends') }}</span><span>{{ $m->access_expires_at?->translatedFormat('j M Y') ?? __('No end') }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Joined') }}</span><span>{{ $m->created_at->translatedFormat('j M Y') }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Last sign-in') }}</span><span>{{ $m->last_login_at?->diffForHumans() ?? '—' }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Last watched') }}</span><span>{{ $m->last_seen_at?->diffForHumans() ?? '—' }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Invited') }}</span><span>{{ $inv ? ($inv->label ?: $inv->code_hint.'…').($inv->creator ? ' · '.__('by :name', ['name' => $inv->creator->name]) : '') : '—' }}</span></div>
            <div style="{{ $row }}"><span>{{ __('Limits') }}</span><span>{{ __(':streams streams, :requests requests/week', ['streams' => $m->max_streams ?? '∞', 'requests' => $m->seerr_access ? ($m->request_limit ?? '∞') : __('none')]) }}</span></div>
            <div style="{{ $row }}"><span>Discord</span><span>{{ $m->discord_username ?? '—' }}</span></div>
            @if ($m->groups->isNotEmpty())
                <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.6rem">
                    @foreach ($m->groups as $group)<x-filament::badge :color="$group->color">{{ $group->name }}</x-filament::badge>@endforeach
                </div>
            @endif
            @if ($m->admin_note)
                <div style="margin-top:.75rem;padding:.6rem .75rem;border-radius:.5rem;background:rgba(234,179,8,.12);font-size:.85rem;line-height:1.5">{!! nl2br(e($m->admin_note)) !!}</div>
            @endif
        </x-filament::section>

        <x-filament::section :heading="__('Servers')" icon="heroicon-o-server-stack">
            @forelse ($m->serverAccounts as $account)
                @php($libs = $account->library_ids === null ? __('All libraries') : $account->mediaServer->libraries->whereIn('external_id', $account->library_ids)->pluck('name')->implode(', '))
                <div style="padding:.4rem 0;border-bottom:1px solid rgba(127,127,127,.12)">
                    <div style="display:flex;justify-content:space-between;gap:1rem;font-size:.9rem">
                        <strong>{{ $account->mediaServer->name }}</strong>
                        <x-filament::badge :color="$account->status->color()">{{ $account->status->label() }}</x-filament::badge>
                    </div>
                    <div style="font-size:.8rem;opacity:.75">{{ $account->remote_username ?? '—' }} · {{ $libs }}</div>
                </div>
            @empty
                <p style="font-size:.85rem;opacity:.7">{{ __('No servers.') }}</p>
            @endforelse
        </x-filament::section>

        @if ($w['stats'])
            <x-filament::section :heading="__('Watching')" icon="heroicon-o-play-circle">
                <div style="{{ $row }}"><span>{{ __('Watched, 7 days') }}</span><span>{{ __(':hours h', ['hours' => $w['stats']['hours7']]) }}</span></div>
                <div style="{{ $row }}"><span>{{ __('Watched, 30 days') }}</span><span>{{ __(':hours h', ['hours' => $w['stats']['hours30']]) }}</span></div>
                <div style="{{ $row }}"><span>{{ __('Plays, 30 days') }}</span><span>{{ $w['stats']['plays30'] }}</span></div>
                @foreach ($w['devices'] as $device)
                    <div style="font-size:.8rem;opacity:.8;padding-top:.3rem">{{ $device['device'] }} · {{ $device['player'] }}</div>
                @endforeach
            </x-filament::section>
        @endif

        <x-filament::section :heading="__('Requests')" icon="heroicon-o-sparkles">
            @forelse ($m->mediaRequests->sortByDesc('id')->take(8) as $request)
                <div style="{{ $row }}"><span>{{ $request->title }}</span><span style="opacity:.6">{{ $request->created_at->translatedFormat('j M') }}</span></div>
            @empty
                <p style="font-size:.85rem;opacity:.7">{{ $m->seerr_access ? __('None yet.') : __('May not request.') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section :heading="__('Log')" icon="heroicon-o-clipboard-document-list">
            @foreach ($this->log() as $event)
                <div style="{{ $row }}"><code style="font-size:.78rem">{{ $event->action }}</code><span style="opacity:.6;white-space:nowrap">{{ $event->created_at->diffForHumans(short: true) }}</span></div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
