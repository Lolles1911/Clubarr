{{-- One wizard step: the admin's Markdown (HTML escaped) and an optional button. --}}
<div style="font-size:.95rem;line-height:1.6" class="clubarr-wizard-step">
    <div class="fi-prose" style="max-width:none">{{ $step->html() }}</div>
    @if ($step->button_label && $step->safeButtonUrl())
        <div style="margin-top:.9rem">
            <x-filament::button tag="a" :href="$step->safeButtonUrl()" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square">{{ $step->button_label }}</x-filament::button>
        </div>
    @endif
</div>
