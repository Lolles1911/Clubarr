@php
    $member = $this->member();
    $accounts = $this->accounts();
    $muted = 'opacity:.65';
@endphp
<x-filament-panels::page>
    <style>
        .clubarr-hero{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:1rem 2rem;padding:1.4rem 1.5rem;border-radius:1rem;background:linear-gradient(135deg,color-mix(in oklab,var(--primary-600) 30%,transparent),color-mix(in oklab,var(--primary-400) 8%,transparent));border:1px solid color-mix(in oklab,var(--primary-500) 35%,transparent)}
        .clubarr-hero-title{font-size:clamp(1.4rem,4vw,2rem);font-weight:800;line-height:1.15;margin:0}
        .clubarr-hero-sub{opacity:.8;margin:.35rem 0 0;font-size:.95rem}
        .clubarr-hero-apps{display:flex;flex-wrap:wrap;gap:.6rem}
        .clubarr-row-title{font-weight:700;font-size:1.1rem;margin:0 0 .6rem}
        .clubarr-row{display:flex;gap:.8rem;overflow-x:auto;padding-bottom:.5rem;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch}
        .clubarr-poster{flex:0 0 clamp(6.75rem,27vw,9.5rem);margin:0;scroll-snap-align:start}
        .clubarr-poster img{width:100%;aspect-ratio:2/3;object-fit:cover;border-radius:.65rem;background:rgba(127,127,127,.18);box-shadow:0 4px 14px rgba(0,0,0,.25)}
        .clubarr-poster figcaption{font-size:.8rem;line-height:1.25;margin-top:.35rem;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
        .clubarr-poster figcaption span{opacity:.6}
        .clubarr-progress{height:4px;border-radius:4px;background:rgba(127,127,127,.3);margin-top:.3rem}
        .clubarr-progress div{height:100%;background:var(--primary-500);border-radius:4px}
        @media (max-width:640px){.clubarr-hero{padding:1.1rem}.clubarr-hero-apps{width:100%}.clubarr-hero-apps>*{flex:1 1 100%;justify-content:center}}
    </style>
    @foreach ($this->banners() as $banner)
        <div role="status" style="display:flex;gap:.75rem;align-items:flex-start;padding:.9rem 1.1rem;border-radius:.75rem;border:1px solid rgba(139,92,246,.45);background:rgba(139,92,246,.12)">
            <x-filament::icon icon="heroicon-o-megaphone" style="width:1.25rem;height:1.25rem;flex:none;margin-top:.1rem;color:#8b5cf6" />
            <div style="font-size:.92rem;line-height:1.5">
                <div style="font-weight:700">{{ $banner->title }}</div>
                <div>{!! nl2br(e($banner->body)) !!}</div>
            </div>
        </div>
    @endforeach
    @php($active = $accounts->filter(fn ($a) => $a->status === \App\Enums\AccountStatus::Active))
    <section class="clubarr-hero">
        <div>
            <h2 class="clubarr-hero-title">{{ __('Hi :name', ['name' => \Illuminate\Support\Str::before($member->name, ' ')]) }}</h2>
            @if ($member->access_expires_at)
                @if ($member->hasAccessExpired())
                    <p class="clubarr-hero-sub" style="color:rgb(239 68 68)">{{ __('Your access ended on :date.', ['date' => $member->access_expires_at->translatedFormat(__('j F Y'))]) }}</p>
                @else
                    <p class="clubarr-hero-sub">{{ __('Your access lasts until :date.', ['date' => $member->access_expires_at->translatedFormat(__('j F Y'))]) }}</p>
                @endif
                @if (\App\Services\MemberRequests::canAskForTime($member))
                    <div style="margin-top:.6rem">{{ $this->askForTimeAction }}</div>
                @elseif (\App\Models\ExtensionRequest::where('user_id', $member->id)->where('status', 'pending')->exists())
                    <p class="clubarr-hero-sub" style="font-size:.85rem">{{ __('You have asked for more time; you get a mail when it is decided.') }}</p>
                @endif
            @elseif ($active->isNotEmpty())
                <p class="clubarr-hero-sub">{{ __('Pick a server and start watching.') }}</p>
            @endif
        </div>
        @if ($active->isNotEmpty())
            <div class="clubarr-hero-apps">
                @foreach ($active as $account)
                    @php($server = $account->mediaServer)
                    <x-filament::button tag="a" size="lg" :href="$server->watchUrl()" target="_blank" rel="noopener noreferrer" icon="heroicon-m-play">{{ __('Open :server', ['server' => $server->name]) }}</x-filament::button>
                @endforeach
            </div>
        @endif
    </section>

    @if ($accounts->isEmpty())
        <x-filament::section>
            <p>{{ __('You have no servers yet. Got an invitation? Open its link, or') }}
                <x-filament::link :href="route('filament.app.join')">{{ __('enter the code here') }}</x-filament::link>.</p>
        </x-filament::section>
    @endif

    @php($rows = $this->posterRows())
    @php($new = $this->newOnServers())
    @foreach (['new' => __('New on the server'), 'continue' => __('Continue watching'), 'popular' => __('Popular now')] as $row => $heading)
        @php($items = $row === 'new' && $new !== [] ? $new : $rows[$row])
        @if ($items !== [] && $accounts->isNotEmpty())
            <section>
                <h2 class="clubarr-row-title">{{ $heading }}</h2>
                <div class="clubarr-row">
                    @foreach ($items as $item)
                        <figure class="clubarr-poster">
                            <img src="{{ isset($item['server']) ? route('filament.app.server-poster', ['key' => $item['key']]) : route('filament.app.poster', ['key' => $item['key']]) }}" alt="" loading="lazy" onerror="this.closest('figure').style.display='none'">
                            @isset($item['percent'])<div class="clubarr-progress"><div style="width:{{ (int) $item['percent'] }}%"></div></div>@endisset
                            <figcaption>{{ $item['title'] }}@if (! empty($item['year'])) <span>{{ $item['year'] }}</span>@endif</figcaption>
                        </figure>
                    @endforeach
                </div>
            </section>
        @endif
    @endforeach

    @php($soon = $this->comingSoon())
    @if ($soon !== [])
        <section>
            <h2 class="clubarr-row-title">{{ __('Coming soon') }}</h2>
            <div class="clubarr-row">
                @foreach ($soon as $item)
                    <figure class="clubarr-poster">
                        @if ($item['tmdb'])
                            <img src="{{ route('filament.app.tmdb', ['file' => $item['tmdb']]) }}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">
                        @else
                            <div style="width:100%;aspect-ratio:2/3;border-radius:.65rem;background:rgba(127,127,127,.18);display:flex;align-items:center;justify-content:center;padding:.5rem;text-align:center;font-size:.8rem;font-weight:600">{{ $item['title'] }}</div>
                        @endif
                        <figcaption>{{ $item['title'] }} <span>{{ $item['detail'] }} · {{ \Illuminate\Support\Carbon::parse($item['date'])->translatedFormat('j M') }}</span></figcaption>
                    </figure>
                @endforeach
            </div>
        </section>
    @endif

    @php($requests = $member->seerr_access ? $member->mediaRequests()->latest()->limit(4)->get() : collect())
    @php($invites = \App\Services\MemberInvites::allowed($member) ? \App\Services\MemberInvites::remaining($member) : null)
    @if ($requests->isNotEmpty() || $invites !== null)
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,18rem),1fr));gap:1rem">
            @if ($requests->isNotEmpty())
                <x-filament::section :heading="__('Your latest requests')" :compact="true">
                    @foreach ($requests as $request)
                        <div style="display:flex;justify-content:space-between;font-size:.85rem;padding:.2rem 0"><span>{{ $request->title }}</span><span style="opacity:.6">{{ $request->created_at->translatedFormat('j M') }}</span></div>
                    @endforeach
                    <div style="margin-top:.4rem"><x-filament::link :href="\App\Filament\App\Pages\Requests::getUrl(panel: 'app')" size="sm">{{ __('All requests') }}</x-filament::link></div>
                </x-filament::section>
            @endif
            @if ($invites !== null)
                <x-filament::section :heading="__('Invite a friend')" :compact="true">
                    <p style="font-size:.88rem">{{ $invites === 0 ? __('You have no invitations left this month.') : ($invites === 1 ? __('You can invite 1 more person this month.') : __('You can invite :count more people this month.', ['count' => $invites])) }}</p>
                    @if ($invites > 0)<div style="margin-top:.4rem"><x-filament::link :href="\App\Filament\App\Pages\InviteFriend::getUrl(panel: 'app')" size="sm">{{ __('Make an invitation') }}</x-filament::link></div>@endif
                </x-filament::section>
            @endif
        </div>
    @endif

    @if ($accounts->isNotEmpty())
        <h2 class="clubarr-row-title" style="margin-bottom:-.5rem">{{ __('Your servers') }}</h2>
    @endif
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(100%,24rem),1fr));gap:1rem">
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
        <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem">
            @foreach ([[__('Watched, 7 days'), __(':hours h', ['hours' => $stats['hours7']])], [__('Watched, 30 days'), __(':hours h', ['hours' => $stats['hours30']])], [__('Plays, 30 days'), $stats['plays30']]] as [$label, $value])
                <x-filament::section :compact="true">
                    <div style="opacity:.7;font-size:.8rem">{{ $label }}</div>
                    <div style="font-size:1.5rem;font-weight:700">{{ $value }}</div>
                </x-filament::section>
            @endforeach
        </div>
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

    <div style="display:flex;flex-wrap:wrap;gap:1.25rem;justify-content:flex-end;font-size:.85rem;opacity:.85">
        {{ $this->digestAction }}
        {{ $this->deleteAccountAction }}
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
