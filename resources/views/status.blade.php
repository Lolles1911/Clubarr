{{-- The public status page: servers, uptime and planned maintenance; nothing about members. No scripts. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta http-equiv="refresh" content="120">
    <title>{{ __('Status') }} · {{ $site }}</title>
    <link rel="icon" href="{{ asset('clubarr-icon.svg') }}">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family={{ urlencode(strtolower(str_replace(' ', '-', $font))) }}:400,600,800&display=swap">
    <style>
        :root { --accent: {{ $color }}; --bg: {{ $theme === 'midnight' ? '#0f172a' : '#07070a' }}; --card: {{ $theme === 'midnight' ? '#1e293b' : 'rgba(255,255,255,.04)' }}; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(1000px 500px at 50% -10%, color-mix(in oklab, var(--accent) 28%, transparent), transparent 70%), var(--bg); color: #e4e4e7; font: 16px/1.55 '{{ $font }}', system-ui, sans-serif; }
        main { max-width: 680px; margin: 0 auto; padding: 48px 20px; }
        h1 { margin: 0 0 4px; font-size: 2rem; letter-spacing: -.02em; }
        .sub { color: #a1a1aa; margin: 0 0 28px; }
        .card { background: var(--card); border: 1px solid rgba(255,255,255,.08); border-radius: 16px; padding: 6px 20px; }
        .row { display: grid; grid-template-columns: 1fr auto; gap: 4px 16px; padding: 14px 0; border-top: 1px solid rgba(255,255,255,.06); }
        .row:first-child { border-top: 0; }
        .name { font-weight: 600; display: flex; align-items: center; gap: 10px; }
        .dot { width: 10px; height: 10px; border-radius: 50%; }
        .up { background: #22c55e; box-shadow: 0 0 12px #22c55e88; } .down { background: #ef4444; box-shadow: 0 0 12px #ef444488; } .maintenance { background: #f59e0b; }
        .state { text-align: right; } .numbers { grid-column: 1 / -1; color: #a1a1aa; font-size: .85rem; }
        .planned { margin-top: 18px; color: #fbbf24; font-size: .95rem; }
        a { color: var(--accent); }
    </style>
</head>
<body>
<main>
    <h1>{{ $site }}</h1>
    <p class="sub">{{ __('Status of the servers. Updated every five minutes.') }}</p>
    <section class="card">
        @forelse ($servers as $s)
            <div class="row">
                <span class="name"><span class="dot {{ $s['state'] }}"></span>{{ $s['name'] }}</span>
                <span class="state">{{ match ($s['state']) { 'up' => __('Online'), 'maintenance' => __('Maintenance'), default => __('Not answering') } }}</span>
                <span class="numbers">
                    {{ __('Last 24 hours: :p', ['p' => $s['day'] === null ? '—' : $s['day'].' %']) }} · {{ __('Last 30 days: :p', ['p' => $s['month'] === null ? '—' : $s['month'].' %']) }}
                </span>
            </div>
        @empty
            <div class="row"><span>{{ __('No servers.') }}</span></div>
        @endforelse
    </section>
    @foreach ($maintenance as $planned)
        <p class="planned">{{ __('Planned maintenance') }}: {{ $planned }}</p>
    @endforeach
    <p style="margin-top:28px;font-size:.9rem"><a href="{{ url('/') }}">{{ $site }}</a></p>
</main>
</body>
</html>
