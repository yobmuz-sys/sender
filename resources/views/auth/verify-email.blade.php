<x-auth-layout title="Confirm your email address">
    <x-slot:aside>
        @foreach ([
            'The message goes to the address you used when creating your account.',
            'Opening the link in that message confirms the address and finishes setting up.',
            'Until then the workspace stays closed, so nothing can be sent from an address that was never confirmed.',
        ] as $point)
            <li class="flex gap-3 text-sm leading-6 text-slate-300">
                <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7.5"/>
                </svg>
                {{ $point }}
            </li>
        @endforeach
    </x-slot:aside>

    <div class="mx-auto w-full max-w-sm">
        <span class="inline-flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700" aria-hidden="true">
            <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2.5" y="5" width="19" height="14" rx="2.5"/>
                <path d="m3.5 7.5 7.36 5.26a2 2 0 0 0 2.28 0L20.5 7.5"/>
            </svg>
        </span>

        <h1 class="mt-4 text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
            Confirm your email address
        </h1>

        <p class="mt-2 text-sm leading-6 text-slate-600">
            Your account is created. One step remains before you can use your workspace.
        </p>

        {{--
            The wording of this notice is the application's, not this view's. What
            it must never become is an instruction to the person reading it: how
            mail is delivered, how long a link is honoured, and whether the platform
            itself is configured are not facts a visitor can act on, and naming
            them would only describe the inside of the product to someone standing
            outside it. A recovery step that helps is offered instead.
        --}}
        <div class="mt-6 rounded-xl border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">
                Confirmation link sent to
            </p>
            <p class="mt-1 break-all text-base font-medium text-slate-900">
                {{ auth()->user()->email }}
            </p>
        </div>

        @if (session('status'))
            <div role="status" class="mt-4 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7.5"/>
                </svg>
                <p>{{ session('status') }}</p>
            </div>
        @endif

        <ol class="mt-6 space-y-3">
            @foreach ([
                'Open your inbox and find the message we sent.',
                'Open the confirmation link inside it.',
                'Your workspace opens once the address is confirmed.',
            ] as $index => $step)
                <li class="flex gap-3 text-sm leading-6 text-slate-700">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-indigo-600 text-xs font-semibold text-white">
                        {{ $index + 1 }}
                    </span>
                    <span>{{ $step }}</span>
                </li>
            @endforeach
        </ol>

        <p class="mt-6 text-sm leading-6 text-slate-600">
            The link stops working after a while. If it has expired, or the message never arrived,
            ask for a new one.
        </p>

        <form method="POST" action="{{ route('verification.send') }}" class="mt-4">
            @csrf
            <button type="submit"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Send a new confirmation email
            </button>
        </form>

        <div class="mt-6 rounded-xl border border-slate-200 p-4">
            <h2 class="text-sm font-semibold text-slate-900">Nothing arrived?</h2>
            <ul class="mt-2 space-y-1.5 text-sm leading-6 text-slate-600">
                <li>Check your spam or junk folder.</li>
                <li>Make sure the address above is the one you used when creating your account.</li>
                <li>Then ask for a new confirmation email.</li>
            </ul>
        </div>

        <div class="mt-6 border-t border-slate-200 pt-6 text-center text-sm">
            <p class="text-slate-600">
                Wrong account, or not yours?
            </p>
            <form method="POST" action="{{ route('logout') }}" class="mt-1 flex justify-center">
                @csrf
                <button type="submit"
                        class="inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Sign out
                </button>
            </form>
        </div>
    </div>
</x-auth-layout>