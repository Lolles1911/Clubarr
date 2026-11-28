<x-filament-panels::page>
    @if ($shareLink)
        <x-filament::section :heading="__('Here is the invitation link')" :description="__('It is shown only this once, and works for :days days.', ['days' => \App\Services\MemberInvites::VALID_DAYS])">
            <x-slot name="afterHeader"><x-filament::link tag="button" wire:click="closeShare" color="gray">{{ __('Close') }}</x-filament::link></x-slot>
            @php($site = (string) app(\App\Services\SiteSettings::class)->get('site_name'))
            <x-share-link :link="$shareLink" :title="$site" :text="__(':name invites you to :site! Open the link to join:', ['name' => $this->member()->name, 'site' => $site])" />
        </x-filament::section>
    @endif
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
            <div>
                <p style="font-size:1rem;font-weight:600">{{ $this->remaining() === 0 ? __('You have no invitations left this month.') : ($this->remaining() === 1 ? __('You can invite 1 more person this month.') : __('You can invite :count more people this month.', ['count' => $this->remaining()])) }}</p>
                <p style="opacity:.75;font-size:.9rem;margin-top:.25rem">{{ __('Each invitation is for one person and works for :days days.', ['days' => \App\Services\MemberInvites::VALID_DAYS]) }}</p>
            </div>
            {{ $this->inviteAction }}
        </div>
    </x-filament::section>

    @php($mine = $this->mine())
    @if ($mine->isNotEmpty())
        <x-filament::section :heading="__('Your invitations')">
            <ul style="display:flex;flex-direction:column;gap:.6rem;margin:0;padding:0;list-style:none;font-size:.92rem">
                @foreach ($mine as $invitation)
                    @php($joined = $invitation->accounts->pluck('user.name')->filter()->unique()->implode(', '))
                    <li style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                        <span>{{ $invitation->label }} <span style="opacity:.6">· {{ $invitation->created_at->translatedFormat('j M') }}</span></span>
                        <x-filament::badge :color="$joined ? 'success' : ($invitation->isUsable() ? 'warning' : 'gray')">
                            {{ $joined ? __('Joined: :name', ['name' => $joined]) : ($invitation->isUsable() ? __('Waiting') : __('Expired')) }}
                        </x-filament::badge>
                    </li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    <x-filament-actions::modals />
</x-filament-panels::page>
