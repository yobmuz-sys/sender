{{--
    Public layout.

    Deliberately separate from `x-layout`, which is the signed-in application
    shell. A marketing page has a different job from a workspace page: it must
    not carry the application navigation, breadcrumbs, session flashes or the
    internal build footer. Sharing one shell between the two is what makes a
    landing page look like an internal screen.

    It also carries no JavaScript of its own. The section links collapse on
    small screens with a plain `details` disclosure, so the header works before
    any bundle has finished loading and cannot be broken by a script error.
--}}

@props(['title' => null])

@php
    $brand = config('app.name');
    $sections = [
        ['href' => '#workflow', 'label' => 'How it works'],
        ['href' => '#features', 'label' => 'Features'],
        ['href' => '#responsible', 'label' => 'Responsible sending'],
    ];
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Extract email addresses from text and public web pages, review exactly what was found, and connect and verify your own SMTP account before preparing to send.">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ $brand }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
    Skip to content
</a>

<div class="flex min-h-full flex-col">
    <header class="border-b border-slate-200 bg-white/95 backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center justify-between gap-x-6 gap-y-3 px-4 py-3 sm:px-6 lg:px-8">
            <a href="{{ route('home') }}" class="flex items-center gap-2 text-base font-semibold tracking-tight text-slate-900">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                        <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
                    </svg>
                </span>
                {{ $brand }}
            </a>

            <nav class="hidden items-center gap-1 md:flex" aria-label="Product">
                @foreach ($sections as $section)
                    <a href="{{ $section['href'] }}"
                       class="rounded-md px-3 py-2 text-sm font-medium text-slate-600 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        {{ $section['label'] }}
                    </a>
                @endforeach
            </nav>

            <div class="flex items-center gap-2">
                @auth
                    <a href="{{ route('dashboard') }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Open workspace
                    </a>
                @else
                    <a href="{{ route('login') }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100 hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Log in
                    </a>
                    <a href="{{ route('register') }}"
                       class="inline-flex min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Get started
                    </a>
                @endauth

                <details class="relative md:hidden">
                    <summary class="inline-flex min-h-11 cursor-pointer list-none items-center justify-center gap-2 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" aria-hidden="true">
                            <path d="M4 7h16M4 12h16M4 17h16"/>
                        </svg>
                        <span class="sr-only">Section menu</span>
                    </summary>

                    <nav class="absolute right-0 z-20 mt-2 w-56 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" aria-label="Product sections">
                        @foreach ($sections as $section)
                            <a href="{{ $section['href'] }}"
                               class="block rounded-lg px-3 py-3 text-sm font-medium text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500">
                                {{ $section['label'] }}
                            </a>
                        @endforeach
                    </nav>
                </details>
            </div>
        </div>
    </header>

    <main id="main" class="flex-1">
        {{ $slot }}
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-3 lg:px-8">
            <div class="md:col-span-2">
                <p class="flex items-center gap-2 text-sm font-semibold text-slate-900">
                    <span class="flex h-7 w-7 items-center justify-center rounded-md bg-indigo-600 text-white" aria-hidden="true">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                            <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
                        </svg>
                    </span>
                    {{ $brand }}
                </p>
                <p class="mt-3 max-w-md text-sm leading-6 text-slate-600">
                    A workspace for collecting email addresses, reviewing exactly what was
                    found, and connecting and verifying the mail account you already use.
                </p>
            </div>

            <nav aria-label="Footer">
                <h2 class="text-sm font-semibold text-slate-900">Get started</h2>
                <ul class="mt-3 space-y-2 text-sm">
                    <li>
                        <a href="{{ route('register') }}"
                           class="inline-flex min-h-11 items-center text-slate-600 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Create an account
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('login') }}"
                           class="inline-flex min-h-11 items-center text-slate-600 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Log in
                        </a>
                    </li>
                    <li>
                        <a href="{{ route('password.request') }}"
                           class="inline-flex min-h-11 items-center text-slate-600 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Reset your password
                        </a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="border-t border-slate-200">
            <div class="mx-auto max-w-6xl px-4 py-5 text-xs text-slate-500 sm:px-6 lg:px-8">
                <p>&copy; {{ now()->year }} {{ $brand }}. All rights reserved.</p>
            </div>
        </div>
    </footer>
</div>
</body>
</html>
