@props(['link', 'title', 'text'])
{{-- An invitation link with Copy, Share (the phone's own share menu) and a QR code made in the browser. --}}
<div x-data="{ link: @js($link), qr: false }" style="display:flex;flex-direction:column;gap:.75rem">
    <div style="font-family:ui-monospace,monospace;font-size:.85rem;word-break:break-all;padding:.6rem .75rem;border-radius:.5rem;background:rgba(127,127,127,.14)">{{ $link }}</div>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
        <x-filament::button size="sm" icon="heroicon-m-clipboard-document" x-on:click="{{ \App\Support\Copy::handler($link) }}">{{ __('Copy link') }}</x-filament::button>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-share" x-on:click="if (! clubarrShare(@js($title), @js($text), link)) { {{ \App\Support\Copy::handler($text.' '.$link) }} }">{{ __('Share') }}</x-filament::button>
        <x-filament::button size="sm" color="gray" icon="heroicon-m-qr-code" x-on:click="qr = ! qr">{{ __('QR code') }}</x-filament::button>
    </div>
    <div x-show="qr" x-cloak style="display:flex;flex-direction:column;align-items:flex-start;gap:.5rem">
        <div x-init="$el.innerHTML = clubarrQr(link)" style="width:12rem;background:#fff;border-radius:.5rem;padding:.25rem"></div>
        <x-filament::link tag="button" size="sm" icon="heroicon-m-arrow-down-tray" x-on:click="clubarrQrDownload(link, 'invitation')">{{ __('Download the QR code') }}</x-filament::link>
    </div>
</div>
