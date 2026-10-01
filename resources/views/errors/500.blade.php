<x-layout>
    <x-slot:title>$title</x-slot:title>

    <div class="mx-auto max-w-lg py-16 text-center">
        <p class="text-sm font-semibold uppercase tracking-wide text-slate-400">Error $code</p>
        <h1 class="mt-2 text-2xl font-semibold text-slate-900">$title</h1>
        <p class="mt-3 text-sm text-slate-600">The error has been recorded. Nothing about your account or your data is shown here.</p>

        <div class="mt-8 flex flex-wrap justify-center gap-3">
            <a href="{{ route('dashboard') }}"
               class="inline-flex items-center rounded-md bg-slate-900 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Back to dashboard
            </a>
            <a href="{{ route('home') }}"
               class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Home
            </a>
        </div>
    </div>
</x-layout>