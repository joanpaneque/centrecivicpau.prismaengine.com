<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="color-scheme: light;">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light">

        <script>
            (function () {
                document.documentElement.classList.remove('dark');
                try {
                    localStorage.removeItem('appearance');
                    document.cookie = 'appearance=; path=/; max-age=0; SameSite=Lax';
                } catch (e) {}
            })();
        </script>

        <style>
            html {
                color-scheme: light;
                background-color: #ffffff;
            }

            body {
                background-color: #ffffff;
                color: #0a0a0a;
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
