{{-- The public front page (Landing). Everything typed by the admin is escaped; no scripts. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <meta name="color-scheme" content="dark">
    <title>{{ $site }}</title>
    <link rel="icon" href="{{ asset('clubarr-icon.svg') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="stylesheet" href="https://fonts.bunny.net/css?family={{ urlencode(strtolower(str_replace(' ', '-', $font))) }}:400,600,800&display=swap">
    <style>
        :root { --accent: {{ $color }}; --bg: {{ $theme === 'midnight' ? '#0f172a' : '#07070a' }}; --card: {{ $theme === 'midnight' ? '#1e293b' : 'rgba(255,255,255,.04)' }}; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: radial-gradient(1200px 600px at 50% -10%, color-mix(in oklab, var(--accent) 35%, transparent), transparent 70%), var(--bg);
            color: #e4e4e7; font: 16px/1.55 '{{ $font }}', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        main { max-width: 960px; margin: 0 auto; padding: 56px 20px 40px; text-align: center; }
        .logo { max-height: 64px; max-width: 260px; }
        h1 { font-size: clamp(2rem, 5vw, 3rem); margin: 18px 0 8px; letter-spacing: -.02em; }
        .text { max-width: 620px; margin: 0 auto 28px; color: #a1a1aa; font-size: 1.1rem; }
        .buttons { display: flex; flex-wrap: wrap; gap: 12px; justify-content: center; margin-bottom: 40px; }
        .btn { display: inline-block; padding: 12px 24px; border-radius: {{ $theme === 'midnight' ? '10px' : '999px' }}; font-weight: 600; text-decoration: none; color: #fff; border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.06); }
        .btn.primary { background: linear-gradient(135deg, var(--accent), color-mix(in oklab, var(--accent) 70%, #000)); border-color: transparent; box-shadow: 0 10px 30px -10px var(--accent); }
        .btn:hover { filter: brightness(1.12); }
        .posters { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(30%, 120px), 1fr)); gap: 12px; margin: 0 auto 40px; max-width: 900px; }
        .posters img { width: 100%; aspect-ratio: 2/3; object-fit: cover; border-radius: 10px; background: #18181b; box-shadow: 0 8px 24px rgba(0,0,0,.4); }
        .status { max-width: 520px; margin: 0 auto; text-align: left; background: var(--card); border: 1px solid rgba(255,255,255,.08); border-radius: 14px; padding: 16px 20px; }
        .status h2 { font-size: .95rem; margin: 0 0 10px; color: #a1a1aa; text-transform: uppercase; letter-spacing: .06em; }
        .row { display: flex; justify-content: space-between; padding: 6px 0; border-top: 1px solid rgba(255,255,255,.06); }
        .row:first-of-type { border-top: 0; }
        .dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 8px; }
        .up { background: #22c55e; } .down { background: #ef4444; } .maintenance { background: #f59e0b; }
        .planned { margin-top: 10px; font-size: .9rem; color: #fbbf24; }
    </style>
</head>
<body>
<main>
    @if ($logo)
        <img class="logo" src="{{ $logo }}" alt="{{ $site }}">
    @endif
    <h1>{{ $site }}</h1>
    @if ($text !== '')
        <p class="text">{!! nl2br(e($text)) !!}</p>
    @endif

    <div class="buttons">
        <a class="btn primary" href="{{ $login }}">{{ __('Sign in') }}</a>
        @if ($apply)
            <a class="btn" href="{{ $apply }}">{{ __('Apply to join') }}</a>
        @endif
        @if ($waitlist)
            <a class="btn" href="{{ $waitlist }}">{{ __('Join the waiting list') }}</a>
        @endif
    </div>

    @if ($posters !== [])
        <div class="posters">
            @foreach ($posters as $poster)
                <img src="{{ route('filament.app.join-poster', ['key' => $poster['key']]) }}" alt="" loading="lazy">
            @endforeach
        </div>
    @endif

    @if ($status !== null && $status['servers'] !== [])
        <section class="status">
            <h2>{{ __('Status') }}</h2>
            @foreach ($status['servers'] as $server)
                <div class="row">
                    <span><span class="dot {{ $server['state'] }}"></span>{{ $server['name'] }}</span>
                    <span>{{ match ($server['state']) { 'up' => __('Online'), 'maintenance' => __('Maintenance'), default => __('Not answering') } }}</span>
                </div>
            @endforeach
            @foreach ($status['maintenance'] as $planned)
                <div class="planned">{{ __('Planned maintenance') }}: {{ $planned }}</div>
            @endforeach
        </section>
    @endif
</main>
</body>
</html>
