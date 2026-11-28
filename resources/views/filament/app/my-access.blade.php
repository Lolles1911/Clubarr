@php
    $member = $this->member();
    $accounts = $this->accounts();
    $muted = 'opacity:.65';
@endphp
<x-filament-panels::page>
    @foreach ($this->banners() as $banner)
        <div role="status" style="display:flex;gap:.75rem;align-items:flex-start;padding:.9rem 1.1rem;border-radius:.75rem;border:1px solid rgba(139,92,246,.45);background:rgba(139,92,246,.12)">
            <x-filament::icon icon="heroicon-o-megaphone" style="width:1.25rem;height:1.25rem;flex:none;margin-top:.1rem;color:#8b5cf6" />
            <div style="font-size:.92rem;line-height:1.5">
                <div style="font-weight:700">{{ $banner->title }}</div>
                <div>{!! nl2br(e($banner->body)) !!}</div>
            </div>
        </div>
    @endforeach
    @if ($member->access_expires_at)
        <x-filament::section :compact="true">
            @if ($member->hasAccessExpired())
                <p style="font-weight:600;color:rgb(var(--danger-500, 239 68 68))">{{ __('Your access ended on :date.', ['date' => $member->access_expires_at->translatedFormat(__('j F Y'))]) }}</p>
            @else
                <p>{{ __('Your access lasts until :date.', ['date' => $member->access_expires_at->translatedFormat(__('j F Y'))]) }}</p>
            @endif
        </x-filament::section>
    @endif

    @if ($accounts->isEmpty())
        <x-filament::section>
            <p>{{ __('You have no servers yet. Got an invitation? Open its link, or') }}
                <x-filament::link :href="route('filament.app.join')">{{ __('enter the code here') }}</x-filament::link>.</p>
        </x-filament::section>
    @endif

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,24rem),1fr));gap:1.5rem">
        @foreach ($accounts as $account)
            @php($server = $account->mediaServer)
            <x-filament::section>
                <x-slot name="heading">{{ $server->name }}</x-slot>
                <x-slot name="description">{{ $server->type->label() }}</x-slot>
                <x-slot name="afterHeader">
                    <x-filament::badge :color="$account->status->color()">{{ $account->status->label() }}</x-filament::badge>
                </x-slot>

                <div style="display:flex;flex-direction:column;gap:.75rem;font-size:.9rem">
                    @if ($account->status === \App\Enums\AccountStatus::Pending && $account->claim_url)
                        <p>{{ __('One step left: choose your user name and password on :server\'s own page.', ['server' => $server->name]) }}</p>
                        <div><x-filament::button tag="a" :href="$account->claim_url" target="_blank" rel="noopener noreferrer">{{ __('Finish on :server', ['server' => $server->name]) }}</x-filament::button></div>
                    @elseif ($account->status === \App\Enums\AccountStatus::Pending)
                        <p>{{ __('Accept the invitation in Plex (plex.tv or the mail from Plex). Then the server shows up in your Plex apps.') }}</p>
                    @elseif ($account->status === \App\Enums\AccountStatus::Disabled)
                        <p>{{ __('Your user is paused. Contact the person who invited you.') }}</p>
                    @else
                        <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem;margin:0">
                            @if ($account->remote_username)
                                <dt style="{{ $muted }}">{{ __('User name') }}</dt><dd style="font-weight:600">{{ $account->remote_username }}</dd>
                            @endif
                            @php($libraries = $account->library_ids === null ? null : $server->libraries->whereIn('external_id', $account->library_ids)->pluck('name'))
                            <dt style="{{ $muted }}">{{ __('Libraries') }}</dt><dd>{{ $libraries === null ? __('All') : $libraries->implode(', ') }}</dd>
                        </dl>
                        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:1rem;padding-top:.25rem">
                            <x-filament::button tag="a" :href="$server->type === \App\Enums\ServerType::Plex ? 'https://app.plex.tv' : $server->openUrl()" target="_blank" rel="noopener noreferrer" icon="heroicon-m-play">{{ __('Open :server', ['server' => $server->name]) }}</x-filament::button>
                            @if ($this->canChangePassword($account))
                                {{ ($this->changePasswordAction)(['account' => $account->id]) }}
                            @endif
                        </div>
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>

    @php($stats = $this->stats())
    @if ($stats !== null && $accounts->isNotEmpty())
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:1rem">
            @foreach ([[__('Watched, 7 days'), __(':hours h', ['hours' => $stats['hours7']])], [__('Watched, 30 days'), __(':hours h', ['hours' => $stats['hours30']])], [__('Plays, 30 days'), $stats['plays30']]] as [$label, $value])
                <x-filament::section :compact="true">
                    <div style="opacity:.7;font-size:.8rem">{{ $label }}</div>
                    <div style="font-size:1.5rem;font-weight:700">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>
        @php($rows = $this->posterRows())
        @foreach (['continue' => __('Continue watching'), 'popular' => __('Popular now'), 'new' => __('New on the server')] as $row => $heading)
            @if ($rows[$row] !== [])
                <div>
                    <h2 style="font-weight:700;font-size:1.05rem;margin:.25rem 0 .6rem">{{ $heading }}</h2>
                    <div style="display:flex;gap:.75rem;overflow-x:auto;padding-bottom:.4rem">
                        @foreach ($rows[$row] as $item)
                            <div style="flex:0 0 7.5rem">
                                <img src="{{ route('filament.app.poster', ['key' => $item['key']]) }}" alt="{{ $item['title'] }}" loading="lazy" onerror="this.parentNode.style.display='none'" style="width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.6rem;background:rgba(127,127,127,.18)">
                                @isset($item['percent'])<div style="height:4px;border-radius:4px;background:rgba(127,127,127,.3);margin-top:.3rem"><div style="height:100%;width:{{ (int) $item['percent'] }}%;background:#8b5cf6;border-radius:4px"></div></div>@endisset
                                <div style="font-size:.8rem;margin-top:.3rem;line-height:1.25">{{ $item['title'] }}</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endforeach
    @endif

    @if ($this->discordEnabled())
        <x-filament::section :heading="__('Discord')" :compact="true">
            @if ($member->discord_id)
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <span>{{ __('Linked as :name.', ['name' => $member->discord_username]) }}</span>
                    <form method="POST" action="{{ route('filament.app.discord.disconnect') }}">@csrf<x-filament::button type="submit" color="gray" size="sm">{{ __('Unlink') }}</x-filament::button></form>
                </div>
            @else
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                    <span style="opacity:.8">{{ __('Link your Discord account to get the members\' role on our Discord.') }}</span>
                    <x-filament::button tag="a" :href="route('filament.app.discord.connect')" size="sm">{{ __('Link Discord') }}</x-filament::button>
                </div>
            @endif
        </x-filament::section>
    @endif

    @if ($this->helpText())
        <x-filament::section :heading="__('Getting started')">
            <div style="font-size:.9rem;line-height:1.6">{!! nl2br(e($this->helpText())) !!}</div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
