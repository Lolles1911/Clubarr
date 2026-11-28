<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:1rem">
            <p style="font-size:.95rem">{{ __('A film that won\'t play, missing subtitles, can\'t sign in? Tell us here.') }}</p>
            {{ $this->reportAction }}
        </div>
    </x-filament::section>

    @php($guides = $this->guides())
    @if ($guides->isNotEmpty())
        <h2 style="font-size:1.1rem;font-weight:600;margin-top:.5rem">{{ __('Guides') }}</h2>
        @foreach ($guides as $step)
            <x-filament::section :heading="$step->title" :description="$step->mediaServer?->name" collapsible collapsed>
                @include('filament.app.wizard-step', ['step' => $step])
            </x-filament::section>
        @endforeach
        <h2 style="font-size:1.1rem;font-weight:600;margin-top:.5rem">{{ __('Your messages to us') }}</h2>
    @endif

    @foreach ($this->reports() as $report)
        <x-filament::section :heading="$report->subject" :description="$report->created_at->translatedFormat('j M Y').($report->mediaServer ? ' · '.$report->mediaServer->name : '')">
            <x-slot name="afterHeader">
                <x-filament::badge :color="$report->status === 'closed' ? 'gray' : ($report->replied_at ? 'info' : 'warning')">
                    {{ $report->status === 'closed' ? __('Closed') : ($report->replied_at ? __('Answered') : __('Open')) }}
                </x-filament::badge>
            </x-slot>
            <div style="font-size:.9rem;line-height:1.55;opacity:.85">{!! nl2br(e($report->body)) !!}</div>
            @if ($report->reply)
                <div style="margin-top:.8rem;padding:.75rem .9rem;border-left:3px solid var(--primary-500);background:rgba(127,127,127,.1);border-radius:.5rem;font-size:.9rem;line-height:1.55">
                    <div style="font-weight:600;margin-bottom:.2rem">{{ __('Answer') }}</div>
                    {!! nl2br(e($report->reply)) !!}
                </div>
            @endif
        </x-filament::section>
    @endforeach

    <x-filament-actions::modals />
</x-filament-panels::page>
