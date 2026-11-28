{{-- My page → Your access: pause, family users, moving server (each only when the admin allows it). --}}
<div style="display:grid;gap:1rem">
    @if ($member->self_paused_at)
        <x-filament::section :compact="true">
            <p>{{ __('Your access is paused until :date. Come back whenever you like.', ['date' => $member->self_pause_until?->translatedFormat(__('j F Y'))]) }}</p>
            <div style="margin-top:.6rem">{{ $page->resumeAction }}</div>
        </x-filament::section>
    @endif

    @if ($familyMax > 0)
        <x-filament::section :heading="__('Your family')" :description="__('Up to :count extra users, with your libraries.', ['count' => $familyMax])" :compact="true">
            @forelse ($member->extraUsers()->with('mediaServer')->get() as $extra)
                <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:.35rem 0;border-top:1px solid rgba(127,127,127,.15)">
                    <span><strong>{{ $extra->label }}</strong> · {{ $extra->mediaServer->name }} · {{ $extra->remote_username }}
                        @if ($extra->status !== \App\Enums\AccountStatus::Active) <x-filament::badge color="gray">{{ __('Paused') }}</x-filament::badge>@endif</span>
                    {{ ($page->removeFamilyAction)(['extra' => $extra->id]) }}
                </div>
            @empty
                <p style="opacity:.75;font-size:.9rem">{{ __('No extra users yet.') }}</p>
            @endforelse
            <div style="margin-top:.75rem">{{ $page->addFamilyAction }}</div>
        </x-filament::section>
    @endif

    <div style="display:flex;flex-wrap:wrap;gap:.75rem">
        {{ $page->pauseAction }}
        {{ $page->moveServerAction }}
    </div>
</div>
