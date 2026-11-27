@php
    $member = $this->member();
    $accounts = $this->accounts();
    $muted = 'opacity:.65';
@endphp
<x-filament-panels::page>
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

    @if ($this->helpText())
        <x-filament::section :heading="__('Getting started')">
            <div style="font-size:.9rem;line-height:1.6">{!! nl2br(e($this->helpText())) !!}</div>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
