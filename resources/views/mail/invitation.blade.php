<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $site }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden">
            <tr><td style="background:{{ $color }};padding:28px 28px 24px;text-align:center">
                @if ($logo)
                    <img src="{{ $logo }}" alt="{{ $site }}" style="max-height:44px;max-width:220px">
                @else
                    <div style="font-size:22px;font-weight:800;color:#ffffff">{{ $site }}</div>
                @endif
            </td></tr>
            <tr><td style="padding:28px">
                <h1 style="margin:0 0 12px;font-size:22px;line-height:1.3">
                    @if ($reminder)
                        {{ __('Your invitation is still waiting') }}
                    @elseif ($from)
                        {{ __(':name invites you to :site', ['name' => $from, 'site' => $site]) }}
                    @else
                        {{ __('You are invited to :site', ['site' => $site]) }}
                    @endif
                </h1>
                @if (filled($greeting))
                    <div style="margin:0 0 18px;padding:14px 16px;border-left:4px solid {{ $color }};background:#fafafa;border-radius:8px;font-size:15px;line-height:1.55">{!! nl2br(e($greeting)) !!}</div>
                @endif
                @if ($servers !== [])
                    <p style="margin:0 0 6px;font-size:15px;line-height:1.55">{{ __('You get access to:') }}</p>
                    <ul style="margin:0 0 16px;padding-left:20px;font-size:15px;line-height:1.6">
                        @foreach ($servers as $server)<li>{{ $server }}</li>@endforeach
                    </ul>
                @endif
                <p style="margin:0 0 22px;font-size:15px;line-height:1.55;color:#52525b">
                    {{ $days ? __('Your access lasts :days days from when you join.', ['days' => $days]) : '' }}
                    {{ $expires ? __('The invitation can be used until :date.', ['date' => $expires]) : '' }}
                </p>
                <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 22px"><tr><td style="border-radius:10px;background:{{ $color }}">
                    <a href="{{ $link }}" style="display:inline-block;padding:14px 28px;font-size:16px;font-weight:700;color:#ffffff;text-decoration:none">{{ __('Accept the invitation') }}</a>
                </td></tr></table>
                <p style="margin:0;font-size:13px;line-height:1.5;color:#71717a">{{ __('Open the link to make your account and start watching. It is personal; don\'t share it.') }}</p>
                <p style="margin:10px 0 0;font-size:12px;line-height:1.5;color:#a1a1aa;word-break:break-all">{{ $link }}</p>
            </td></tr>
        </table>
        <p style="margin:16px 0 0;font-size:12px;color:#a1a1aa">{{ $site }}</p>
    </td></tr>
</table>
</body>
</html>
