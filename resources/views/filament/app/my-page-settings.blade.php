{{-- My page → Settings: Discord. (The weekly mail and deleting the account are the actions below.) --}}
@if ($discord)
    <x-filament::section :heading="__('Discord')" :compact="true">
        @if ($member->discord_id)
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                <span>{{ __('Linked as :name.', ['name' => $member->discord_username]) }}</span>
                <form method="POST" action="{{ route('filament.app.discord.disconnect') }}">@csrf<x-filament::button type="submit" color="gray" size="sm">{{ __('Unlink') }}</x-filament::button></form>
            </div>
        @else
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap">
                <span style="opacity:.8">{{ __('Link your Discord account to get the members\' role on our Discord, and our messages there.') }}</span>
                <x-filament::button tag="a" :href="route('filament.app.discord.connect')" size="sm">{{ __('Link Discord') }}</x-filament::button>
            </div>
        @endif
    </x-filament::section>
@endif
