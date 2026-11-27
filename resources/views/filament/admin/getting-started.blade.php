<x-filament-widgets::widget>
    <x-filament::section :heading="__('Getting started')">
        <ol style="display:flex;flex-direction:column;gap:.6rem">
            @foreach ($this->steps() as $i => $step)
                <li style="display:flex;align-items:center;gap:.75rem">
                    @if ($step['done'])
                        <x-filament::icon icon="heroicon-s-check-circle" style="width:1.25rem;height:1.25rem;color:#22c55e" />
                        <span style="opacity:.6;text-decoration:line-through">{{ $step['label'] }}</span>
                    @else
                        <span style="display:inline-flex;width:1.25rem;height:1.25rem;align-items:center;justify-content:center;border-radius:999px;background:#7c3aed;color:#fff;font-size:.7rem;font-weight:700">{{ $i + 1 }}</span>
                        <x-filament::link :href="$step['url']">{{ $step['label'] }}</x-filament::link>
                    @endif
                </li>
            @endforeach
        </ol>
    </x-filament::section>
</x-filament-widgets::widget>
