{{--
    Authentication shell.

    The signed-in application layout is the wrong shell for a public screen: it
    carries navigation, breadcrumbs, session flashes and a build version, all of
    which describe an internal workspace rather than a door into one. This shell
    gives the authentication pages a single brand treatment, a focused form
    column, and nothing else.

    The supporting column is hidden below `lg` rather than stacked: on a phone
    the form is the only thing anyone came for, and a pitch above it pushes the
    fields below the fold.

    It depends on no JavaScript. The one enhancement it supports, the password
    visibility toggle, degrades to a working form when scripting is unavailable.
--}}

@props(['title' => null])

@php
    $brand = config('app.name');
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ? $title.' · ' : '' }}{{ $brand }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full bg-slate-50 text-slate-800 antialiased">
<a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded-lg focus:bg-indigo-600 focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-white">
    Skip to content
</a>

<div class="flex min-h-full flex-col">
    <header class="border-b border-slate-200 bg-white lg:hidden">
        <div class="mx-auto flex max-w-md items-center px-4 py-3">
            <a href="{{ route('home') }}" class="flex items-center gap-2 text-base font-semibold tracking-tight text-slate-900">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600 text-white" aria-hidden="true">
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                        <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
                    </svg>
                </span>
                {{ $brand }}
            </a>
        </div>
    </header>

    <main id="main" class="flex flex-1 items-center justify-center px-4 py-8 sm:px-6 sm:py-12">
        <div class="w-full max-w-5xl overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm lg:grid lg:grid-cols-2">
            <aside class="hidden bg-slate-900 p-10 lg:flex lg:flex-col lg:justify-between">
                <div>
                    <p class="flex items-center gap-2 text-base font-semibold tracking-tight text-white">
                        <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-600" aria-hidden="true">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                                <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
                            </svg>
                        </span>
                        {{ $brand }}
                    </p>

                    <h2 class="mt-8 text-2xl font-semibold tracking-tight text-white">
                        Your email workflow, in one workspace.
                    </h2>

                    <p class="mt-3 text-sm leading-6 text-slate-300">
                        Extraction, checks and your mail setup, kept together and visible while
                        they run.
                    </p>
                </div>

                <ul class="mt-10 space-y-4">
                    @foreach ([
                        'Extract addresses from text or a single public page, and see what each run found.',
                        'Read check outcomes honestly, including the ones that stay uncertain.',
                        'Connect the SMTP account you already use and verify it before anything depends on it.',
                    ] as $point)
                        <li class="flex gap-3 text-sm leading-6 text-slate-300">
                            <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m5 12.5 4.5 4.5L19 7.5"/>
                            </svg>
                            {{ $point }}
                        </li>
                    @endforeach
                </ul>

                <p class="mt-10 text-xs leading-5 text-slate-400">
                    Contact lists and campaign sending are still being built.
                </p>
            </aside>

            <div class="px-5 py-8 sm:px-10 sm:py-12">
                {{ $slot }}
            </div>
        </div>
    </main>

    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto max-w-md px-4 py-5 text-center text-xs text-slate-500">
            <p>&copy; {{ now()->year }} {{ $brand }}</p>
            <p class="mt-1">
                <a href="{{ route('home') }}"
                   class="inline-flex min-h-11 items-center underline underline-offset-2 transition hover:text-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Back to the home page
                </a>
            </p>
        </div>
    </footer>
</div>
</body>
</html>