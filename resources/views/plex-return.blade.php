<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $site }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #0a0a0c; color: #e4e4e7; font: 16px/1.5 system-ui, sans-serif; text-align: center; }
        a { color: #a78bfa; }
    </style>
</head>
<body>
    <main>
        <p>{{ __('Signed in with Plex.') }}</p>
        <p><a href="{{ $back }}">{{ __('Back to :site', ['site' => $site]) }}</a></p>
    </main>
    <script>
        (() => {
            const back = @js($back);
            if (window.opener && ! window.opener.closed) {
                try { window.opener.focus() } catch (e) {}
                window.close();
            }
            setTimeout(() => window.location.replace(back), 300);
        })();
    </script>
</body>
</html>
