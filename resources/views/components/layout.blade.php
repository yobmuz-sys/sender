<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

    @php
        // Derived from the route rather than declared per view, so a page can
        // never advertise a trail that disagrees with where it actually is.
        $crumbs = \App\Support\Navigation\Breadcrumbs::for(
            request()->route()?->getName() ?? '',
            $title ?? '',
            request()->route()?->parameters() ?? [],
        );

        $nav = $navigation;
        $pending = $pendingRoutes ?? [];
    @endphp

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
    Skip to content
</a>

<div class="flex min-h-full flex-col">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 lg:px-6">
            {{-- Mobile navigation. A native disclosure rather than a scripted
                 drawer: the shell works before any script has run, and keeps
                 working if it never does. --}}
            <details class="relative lg:hidden">
                <summary class="flex min-h-11 min-w-11 cursor-pointer list-none items-center justify-center rounded-lg border border-slate-300 text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">
                        <path d="M4 7h16M4 12h16M4 17h16"/>
                    </svg>
                    <span class="sr-only">Open navigation</span>
                </summary>

                <div class="absolute left-0 z-40 mt-2 max-h-[calc(100vh_-_5rem)] w-[min(20rem,calc(100vw_-_2rem))] overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 shadow-lg">
                    <x-app-nav :navigation="$nav" :pending-routes="$pending" aria-label="Mobile" />
                </div>
            </details>

            <a href="{{ route('dashboard') }}" class="flex items-center gap-2 rounded-lg text-base font-semibold tracking-tight text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                        <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
                    </svg>
                </span>
                <span class="hidden sm:inline">{{ config('app.name') }}</span>
            </a>

            <div class="ml-auto flex items-center gap-2">
                <details class="relative">
                    <summary class="flex min-h-11 cursor-pointer list-none items-center gap-2 rounded-lg px-2 py-1.5 text-sm font-medium text-slate-700 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        <span class="flex h-7 w-7 items-center justify-center rounded-full bg-slate-100 text-xs font-semibold uppercase text-slate-600" aria-hidden="true">
                            {{ \Illuminate\Support\Str::substr((string) auth()->user()->name, 0, 1) }}
                        </span>
                        <span class="hidden max-w-[10rem] truncate sm:inline">{{ auth()->user()->name }}</span>
                        <svg class="h-4 w-4 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m6 9 6 6 6-6"/>
                        </svg>
                        <span class="sr-only">Account menu</span>
                    </summary>

                    <div class="absolute right-0 z-40 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
                        <p class="truncate border-b border-slate-200 px-3 py-2 text-xs text-slate-500">
                            {{ auth()->user()->email }}
                        </p>

                        @foreach ($nav['account'] as $item)
                            <x-nav-link :item="$item" :url="request()->path()" class="mt-1" />
                        @endforeach

                        <form method="POST" action="{{ route('logout') }}" class="mt-1 border-t border-slate-200 pt-1">
                            @csrf
                            <button type="submit"
                                    class="flex min-h-11 w-full items-center rounded-lg px-3 text-sm font-medium text-slate-700 transition hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                Log out
                            </button>
                        </form>
                    </div>
                </details>
            </div>
        </div>
    </header>

    <div class="mx-auto w-full max-w-7xl flex-1 px-4 py-6 lg:flex lg:gap-8 lg:px-6">
        {{-- The sidebar owns primary navigation; the header deliberately does not,
             because eleven links in a bar is a strip, not a menu. --}}
        <aside class="hidden lg:block lg:w-64 lg:shrink-0">
            <div class="sticky top-6 pb-6">
                <x-app-nav :navigation="$nav" :pending-routes="$pending" />
            </div>
        </aside>

        <main id="main" class="min-w-0 flex-1">
            <x-breadcrumbs :items="$crumbs" />

            <x-flash />

            {{ $slot }}
        </main>
    </div>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2 px-4 py-5 text-xs text-slate-500 lg:px-6">
            <span>{{ config('app.name') }}</span>
            <span>Version {{ app()->version() }}</span>
        </div>
    </footer>
</div>
<x-password-toggle-script />
</body>
</html>