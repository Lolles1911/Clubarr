{{-- A member's access at a glance (AccountSummary). --}}
<x-filament::section :heading="__('Your access')" :compact="true">
    <dl style="display:grid;grid-template-columns:max-content 1fr;gap:.4rem 1.25rem;font-size:.9rem">
        <dt style="opacity:.7">{{ __('Access ends') }}</dt><dd>{{ $status['ends'] ?? __('No end') }}</dd>
        <dt style="opacity:.7">{{ __('Servers') }}</dt><dd>{{ $status['servers'] }}</dd>
        @if ($status['groups'] !== [])
            <dt style="opacity:.7">{{ __('Groups') }}</dt><dd>{{ implode(', ', $status['groups']) }}</dd>
        @endif
        @if ($status['requests'] !== null)
            <dt style="opacity:.7">{{ __('Requests') }}</dt><dd>{{ $status['requests'] }}</dd>
        @endif
        @if ($status['invites'] !== null)
            <dt style="opacity:.7">{{ __('Invitations left this month') }}</dt><dd>{{ $status['invites'] }}</dd>
        @endif
    </dl>
</x-filament::section>
