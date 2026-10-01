<x-layout>
    <x-slot:title>Confirm your email address</x-slot:title>

    <div class="mx-auto max-w-xl">
        <x-card>
            <h1 class="text-lg font-semibold text-slate-900">Confirm your email address</h1>

            <p class="mt-3 text-sm text-slate-600">
                We sent a confirmation link to <strong>{{ auth()->user()->email }}</strong>.
                Follow it to finish setting up the account.
            </p>

            <p class="mt-3 text-sm text-slate-600">
                The link expires after {{ config('auth.verification.expire', 60) }} minutes. If it
                has expired, or the message did not arrive, ask for a new one below.
            </p>

            <div class="mt-6 flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('verification.send') }}">
                    @csrf
                    <x-button type="submit" variant="primary">Send a new link</x-button>
                </form>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button variant="secondary">Sign out</x-button>
                </form>
            </div>
        </x-card>

        <x-alert variant="info" title="Nothing arrived?" class="mt-4">
            Check the address you registered with, and your spam folder. On a fresh installation
            the mailer may still be unconfigured — an operator can confirm this with
            <code class="font-mono text-xs">php artisan sender:verify-smtp</code>.
        </x-alert>
    </div>
</x-layout>
