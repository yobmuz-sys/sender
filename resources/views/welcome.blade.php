<x-layout>
    <div class="mx-auto max-w-3xl">
        <h1 class="text-3xl font-semibold text-slate-900">{{ config('app.name') }}</h1>

        <p class="mt-3 text-slate-600">
            A modular, cPanel-compatible platform for data extraction and email campaigns.
        </p>

        <div class="mt-8 grid gap-4 sm:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold text-slate-900">Foundation</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Authentication, role-based authorization, resource limits and host
                    diagnostics are in place.
                </p>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="font-semibold text-slate-900">Later stages</h2>
                <p class="mt-2 text-sm text-slate-600">
                    Extraction, SMTP campaigns, administration and API integration are not
                    implemented yet and are documented as such.
                </p>
            </div>
        </div>

        <p class="mt-8 text-sm text-slate-500">
            Readiness endpoint: <a href="{{ route('health') }}" class="underline">/health</a>
        </p>
    </div>
</x-layout>