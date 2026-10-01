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
    @endphp

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-100 text-slate-800 antialiased"
      x-data="{ mobileNav: false }">
<div class="flex min-h-full flex-col">

    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3">
            <div class="flex items-center gap-6">
                <a href="{{ route('home') }}" class="text-lg font-semibold text-slate-900">
                    {{ config('app.name') }}
                </a>

                @auth
                    @php($nav = $navigation)

                    <nav class="hidden items-center gap-1 md:flex" aria-label="Primary">
                        @foreach ($nav['product'] as $item)
                            <x-nav-link :item="$item" :url="request()->path()" />
                        @endforeach

                        @if ($nav['admin'] !== [])
                            <span class="mx-1 h-5 w-px bg-slate-200" aria-hidden="true"></span>

                            @foreach ($nav['admin'] as $item)
                                <x-nav-link :item="$item" :url="request()->path()" />
                            @endforeach
                        @endif
                    </nav>
                @endauth
            </div>

            <div class="flex items-center gap-3">
                @auth
                    <div class="relative" x-data="{ open: false }">
                        <button type="button" @click="open = !open"
                                class="flex items-center gap-2 rounded-md px-2 py-1 text-sm text-slate-700 hover:bg-slate-100">
                            <span>{{ auth()->user()->name }}</span>
                            <span class="sr-only">Open account menu</span>
                        </button>

                        <div x-show="open" @click.outside="open = false" x-cloak
                             class="absolute right-0 z-20 mt-2 w-52 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                            @foreach ($nav['account'] as $item)
                                <a href="{{ route($item->route) }}"
                                   class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50">
                                    {{ $item->label }}
                                </a>
                            @endforeach

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit"
                                        class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">
                                    Log out
                                </button>
                            </form>
                        </div>
                    </div>
                @else
                    <a href="{{ route('login') }}" class="text-sm text-slate-600 hover:text-slate-900">Log in</a>
                    <a href="{{ route('register') }}"
                       class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-700">
                        Register
                    </a>
                @endauth

                @auth
                    <button type="button" @click="mobileNav = !mobileNav" class="md:hidden"
                            aria-label="Toggle navigation">
                        <span class="sr-only">Toggle navigation</span>
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path stroke-linecap="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/>
                        </svg>
                    </button>
                @endauth
            </div>
        </div>

        @auth
            <nav class="border-t border-slate-200 bg-white px-4 py-3 md:hidden" x-show="mobileNav" x-cloak
                 aria-label="Primary mobile">
                @foreach (array_merge($nav['product'], $nav['admin']) as $item)
                    <x-nav-link :item="$item" :url="request()->path()" class="block py-2" />
                @endforeach
            </nav>
        @endauth
    </header>

    <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-8">
        <x-breadcrumbs :items="$crumbs" />

        <x-flash />

        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-2 px-4 py-5 text-xs text-slate-500">
            <span>{{ config('app.name') }}</span>
            <span>Version {{ app()->version() }}</span>
        </div>
    </footer>
</div>
</body>
</html>
