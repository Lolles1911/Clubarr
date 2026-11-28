@if ($reminder){{ __('Your invitation is still waiting') }}@elseif ($from){{ __(':name invites you to :site', ['name' => $from, 'site' => $site]) }}@else{{ __('You are invited to :site', ['site' => $site]) }}@endif


@if (filled($greeting)){{ $greeting }}

@endif
@if ($servers !== []){{ __('You get access to:') }} {{ implode(', ', $servers) }}

@endif
{{ __('Accept the invitation') }}: {{ $link }}

{{ __('Open the link to make your account and start watching. It is personal; don\'t share it.') }}
