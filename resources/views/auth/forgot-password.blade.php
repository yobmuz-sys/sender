<x-auth-layout title="Forgot password">
    <x-slot:aside>
        @foreach ([
            'You only need the email address you sign in with.',
            'The message contains a link that lets you choose a new password.',
            'The link can be used once, and it stops working after a short time.',
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
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
            Forgot your password?
        </h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">
            Enter the email address associated with your account and we’ll help you reset your
            password. If that address can be used to reset a password, the next step happens by
            email.
        </p>

        {{--
            The confirmation text comes from the reset response itself, so the page
            never has to restate what the application decided. The line beneath it
            carries the part the response cannot: that the message is only sent when
            the address can actually be used, which is why the response is the same
            either way and reveals nothing about the account.
        --}}
        @if (session('status'))
            <div role="status" class="mt-6 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7.5"/>
                </svg>
                <p>
                    {{ session('status') }}
                    <span class="mt-1 block text-emerald-800">
                        If that address can be used to reset a password, a message with the next
                        steps is on its way. Check your spam folder if it has not arrived.
                    </span>
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-800">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" inputmode="email" spellcheck="false" required autofocus
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                       class="mt-1.5 block min-h-12 w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                              {{ $errors->has('email') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">
                <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-1.5" />
            </div>

            <button type="submit"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Email reset link
            </button>
        </form>

        <div class="mt-6 space-y-3 text-center text-sm">
            {{-- Sign in is offered first and more quietly: plenty of people reach
                 this page by accident when their password is perfectly fine. --}}
            <p class="text-slate-600">
                Remembered it?
                <a href="{{ route('login') }}"
                   class="inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Back to sign in
                </a>
            </p>

            <p class="text-slate-600">
                No account yet?
                <a href="{{ route('register') }}"
                   class="inline-flex min-h-11 items-center font-medium text-slate-700 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Create an account
                </a>
            </p>
        </div>
    </div>
</x-auth-layout>