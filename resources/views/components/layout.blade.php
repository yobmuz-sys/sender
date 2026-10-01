<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased">
    <div class="flex min-h-full flex-col">
        <header class="border-b border-slate-200 bg-white">
            <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-4">
                <a href="{{ route('home') }}" class="text-lg font-semibold text-slate-900">
                    {{ config('app.name') }}
                </a>

                <nav class="flex items-center gap-4 text-sm">
                    @auth
                        <a href="{{ route('dashboard') }}" class="text-slate-600 hover:text-slate-900">Dashboard</a>

                        @can(\App\Domain\Users\Permission::SYSTEM_VIEW)
                            <a href="{{ route('diagnostics') }}" class="text-slate-600 hover:text-slate-900">Diagnostics</a>
                        @endcan

                        <span class="text-slate-500">{{ auth()->user()->name }}</span>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-slate-600 hover:text-slate-900">Log out</button>
                        </form>
                    @else
                        <a href="{{ route('login') }}" class="text-slate-600 hover:text-slate-900">Log in</a>
                        <a href="{{ route('register') }}" class="text-slate-600 hover:text-slate-900">Register</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-10">
            @if (session('status'))
                <div class="mb-6 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    {{ session('status') }}
                </div>
            @endif

            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 bg-white">
            <div class="mx-auto max-w-5xl px-4 py-4 text-xs text-slate-500">
                {{ config('app.name') }} &middot; environment: {{ app()->environment() }}
            </div>
        </footer>
    </div>
</body>
</html>