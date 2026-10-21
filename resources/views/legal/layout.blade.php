<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ config('app.name') }}</title>
    <style>
        body { margin: 0; font-family: system-ui, sans-serif; background: #f5f5f5; color: #1a1a1a; line-height: 1.6; }
        main { max-width: 44rem; margin: 2rem auto; padding: 1.5rem 1.5rem 3rem; background: #fff; border-radius: 12px; }
        .draft { margin: 0 0 1.5rem; padding: .75rem 1rem; background: #fff4d6; border: 1px solid #e0b84a; border-radius: 8px; font-weight: 600; }
        h1 { font-size: 1.6rem; margin: 0 0 .25rem; }
        h2 { font-size: 1.1rem; margin: 1.5rem 0 .25rem; }
        .meta { color: #555; margin: 0 0 1rem; }
        nav { margin-top: 2rem; font-size: .9rem; }
        a { color: #1a4fd6; }
    </style>
</head>
<body>
<main>
    <p class="draft" role="note">Draft — not final legal text (Version: draft-1)</p>
    @yield('content')
    <nav><a href="{{ route('terms') }}">Terms of Service</a> · <a href="{{ route('privacy') }}">Privacy Policy</a></nav>
</main>
</body>
</html>
