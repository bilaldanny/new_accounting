<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>


        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <!--favicon-->
        <link rel="icon" href="{{ asset('assets/images/favicon-32x32.png') }}" type="image/png">
        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <!--plugins-->
        @if (! str_starts_with($page['component'] ?? '', 'auth/'))
            <link href="{{ asset('assets/plugins/highcharts/css/highcharts.css') }}" rel="stylesheet">
            <link href="{{ asset('assets/plugins/vectormap/jquery-jvectormap-2.0.2.css') }}" rel="stylesheet">
        @endif
        <link href="../../../css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])

        <script>
            window.Laravel = {
                appName: "{{ config('app.name') }}"
            };
        </script>

        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />

        @if (! str_starts_with($page['component'] ?? '', 'auth/'))
            <!-- Bootstrap JS -->
            <!-- <script src="{{ asset('assets/js/bootstrap.bundle.min.js') }}"></script> -->
            <!--plugins-->
            <script src="{{ asset('assets/js/jquery.min.js') }}"></script>
            <script src="{{ asset('assets/plugins/vectormap/jquery-jvectormap-2.0.2.min.js') }}"></script>
            <script src="{{ asset('assets/plugins/vectormap/jquery-jvectormap-world-mill-en.js') }}"></script>
            <script src="{{ asset('assets/plugins/highcharts/js/highcharts.js') }}"></script>
            <script src="{{ asset('assets/plugins/highcharts/js/exporting.js') }}"></script>
            <script src="{{ asset('assets/plugins/highcharts/js/variable-pie.js') }}"></script>
            <script src="{{ asset('assets/plugins/highcharts/js/export-data.js') }}"></script>
            <script src="{{ asset('assets/plugins/highcharts/js/accessibility.js') }}"></script>
            <script src="{{ asset('assets/plugins/apexcharts-bundle/js/apexcharts.min.js') }}"></script>
            <!-- <script>
                new PerfectScrollbar('.dashboard-top-countries');
            </script> -->
            <script src="{{ asset('assets/js/index.js') }}"></script>
            <!--app JS-->
            <script src="{{ asset('assets/js/app.js') }}"></script>
        @endif
        
    </body>
</html>
