<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
        <meta name="robots" content="noindex, nofollow">
        <meta name="referrer" content="same-origin">

        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                if ('{{ $appearance ?? 'system' }}' === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <style nonce="{{ Vite::cspNonce() }}">
            html { background-color: #f3f1ea; }
            html.dark { background-color: #0c1312; }
        </style>

        <link rel="icon" href="/favicon.svg" type="image/svg+xml">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name') }}</title>
        </x-inertia::head>
    </head>
    <body class="antialiased">
        <x-inertia::app />
    </body>
</html>
