<x-layout>
    <x-slot:title>Dashboard</x-slot:title>

    <div class="rounded-lg border border-slate-200 bg-white p-8 shadow-sm">
        <h1 class="text-xl font-semibold text-slate-900">Welcome, {{ auth()->user()->name }}</h1>

        <dl class="mt-6 grid gap-4 sm:grid-cols-3">
            <div class="rounded-md border border-slate-200 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Account</dt>
                <dd class="mt-1 text-sm text-slate-800">{{ auth()->user()->email }}</dd>
            </div>
            <div class="rounded-md border border-slate-200 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Role</dt>
                <dd class="mt-1 text-sm text-slate-800">{{ auth()->user()->role->label() }}</dd>
            </div>
            <div class="rounded-md border border-slate-200 p-4">
                <dt class="text-xs uppercase tracking-wide text-slate-500">Environment</dt>
                <dd class="mt-1 text-sm text-slate-800">{{ app()->environment() }}</dd>
            </div>
        </dl>

        <p class="mt-6 text-sm text-slate-600">
            The extraction and campaign modules arrive in later stages. This page exists to
            confirm that authentication and authorization are wired correctly.
        </p>
    </div>
</x-layout>