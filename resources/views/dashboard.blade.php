<x-layout>
    <x-slot:title>Dashboard</x-slot:title>

    <x-page-header
        title="Welcome, {{ auth()->user()->name }}"
        description="Your account at a glance."
    />

    @unless (auth()->user()->hasVerifiedEmail())
        <x-alert variant="warning" title="Your email address is not confirmed" class="mb-6">
            Some notifications will not reach you until it is.
            <a href="{{ route('verification.notice') }}" class="font-medium underline">Confirm your address</a>.
        </x-alert>
    @endunless

    <div class="grid gap-4 sm:grid-cols-3">
        <x-stat label="Email" :value="auth()->user()->email" />
        <x-stat label="Role" :value="auth()->user()->role->label()" />
        <x-stat label="Member since" :value="auth()->user()->created_at?->format('j M Y') ?? '—'" />
    </div>

    <div class="mt-8">
        <x-card title="Getting started">
            <p class="text-sm text-slate-600">
                The extraction and campaign modules are not yet available. The navigation above
                lists where each will live; those pages say so plainly rather than pretending
                to work.
            </p>

            <div class="mt-4 flex flex-wrap gap-3">
                <a href="{{ route('account.profile') }}"
                   class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Update your profile
                </a>
                <a href="{{ route('account.security') }}"
                   class="inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    Change your password
                </a>
            </div>
        </x-card>
    </div>
</x-layout>
