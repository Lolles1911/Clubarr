<x-filament-widgets::widget>
    @if ($update = $this->update())
        <x-filament::section icon="heroicon-o-arrow-up-circle" icon-color="primary"
            :heading="__('Clubarr :version is out', ['version' => $update['version']])"
            :description="__('You have :current. In Unraid: Docker → Check for updates → apply update.', ['current' => config('clubarr.version')])">
            <x-filament::link :href="$update['url']" target="_blank" rel="noopener noreferrer" icon="heroicon-m-arrow-top-right-on-square">{{ __('What is new') }}</x-filament::link>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
