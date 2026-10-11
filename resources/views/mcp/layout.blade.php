{{--
    Minimal shell of the MCP OAuth screens (consent and "sin permiso").
    App-owned Blade outside the design folders: flagged for design (Stage E).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        :root { color-scheme: light dark; --bg: #f5f6f8; --card: #ffffff; --text: #1f2937; --muted: #4b5563; --border: #d1d5db; --primary: #1d4ed8; --primary-text: #ffffff; }
        @media (prefers-color-scheme: dark) { :root { --bg: #111827; --card: #1f2937; --text: #f3f4f6; --muted: #d1d5db; --border: #374151; --primary: #3b82f6; --primary-text: #ffffff; } }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box; background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width: 100%; max-width: 28rem; background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; }
        h1 { font-size: 1.25rem; margin: 0 0 12px; }
        p, li { color: var(--muted); }
        .actions { display: flex; gap: 12px; margin-top: 24px; }
        .actions form { flex: 1; }
        button { width: 100%; min-height: 44px; border-radius: 8px; font: inherit; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: transparent; color: var(--text); }
        button.primary { background: var(--primary); border-color: var(--primary); color: var(--primary-text); }
        button:focus-visible { outline: 3px solid var(--primary); outline-offset: 2px; }
    </style>
</head>
<body>
    <main>
        <p>{{ config('app.name') }}</p>
        @yield('content')
    </main>
</body>
</html>
