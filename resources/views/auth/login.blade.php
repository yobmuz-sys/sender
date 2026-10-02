@php
    /*
     * Two kinds of error can arrive on this screen and they must not be drawn the
     * same way.
     *
     * A failed credential check and a rate-limit notice are whole-form events: the
     * visitor cannot fix them by correcting a field, so they belong in an alert
     * above the form. They also have to stay exactly as generic as the backend
     * made them, because anything more specific would tell a stranger which
     * addresses exist.
     *
     * A format or presence error belongs to the field it names, so it stays under
     * that input. The two sets are separated by comparing the messages against the
     * translation keys they came from, which keeps the distinction on the client
     * side of the view without re-deciding it here.
     */
    $genericFailure = __('auth.failed');
    $throttlePrefix = \Illuminate\Support\Str::before((string) __('auth.throttle'), ':seconds');

    $isWholeFormFailure = static fn (?string $message): bool => $message !== null
        && ($message === $genericFailure || \Illuminate\Support\Str::startsWith($message, $throttlePrefix));

    $emailErrors = collect($errors->get('email'))->reject($isWholeFormFailure)->values();
    $wholeFormErrors = collect($errors->get('email'))->filter($isWholeFormFailure)->values();
    $passwordErrors = collect($errors->get('password'))->values();
@endphp

<x-auth-layout title="Sign in">
    <div class="mx-auto w-full max-w-sm">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
            Sign in
        </h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">
            Sign in to your workspace to manage your extractions, your checks and your mail setup.
        </p>

        @if (session('status'))
            <div role="status" class="mt-6 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7.5"/>
                </svg>
                <p>{{ session('status') }}</p>
            </div>
        @endif

        @if ($wholeFormErrors->isNotEmpty())
            <div role="alert" class="mt-6 flex gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 8v4.5M12 16h.01"/>
                </svg>
                <ul class="space-y-1">
                    @foreach ($wholeFormErrors as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-800">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" inputmode="email" spellcheck="false" required autofocus
                       @if ($emailErrors->isNotEmpty()) aria-invalid="true" aria-describedby="email-error" @endif
                       class="mt-1.5 block min-h-12 w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                              {{ $emailErrors->isNotEmpty() ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">
                <x-input-error id="email-error" :messages="$emailErrors->all()" class="mt-1.5" />
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-3">
                    <label for="password" class="block text-sm font-medium text-slate-800">Password</label>
                    <a href="{{ route('password.request') }}"
                       class="text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Forgot password?
                    </a>
                </div>

                <div class="relative mt-1.5">
                    <input id="password" name="password" type="password"
                           autocomplete="current-password" required
                           @if ($passwordErrors->isNotEmpty()) aria-invalid="true" aria-describedby="password-error" @endif
                           class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                  {{ $passwordErrors->isNotEmpty() ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

                    <button type="button" id="password-toggle" aria-controls="password" aria-pressed="false"
                            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-lg text-slate-500 transition hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">
                        <span data-password-label class="sr-only">Show password</span>
                        <svg data-password-icon="show" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <svg data-password-icon="hide" class="hidden h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="M3 3l18 18"/>
                            <path d="M10.6 6.1A9.6 9.6 0 0 1 12 6c6 0 9.5 6 9.5 6a17 17 0 0 1-3.3 3.9"/>
                            <path d="M6.4 7.6A17 17 0 0 0 2.5 12S6 18 12 18a9.4 9.4 0 0 0 3.6-.7"/>
                            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>
                        </svg>
                    </button>
                </div>

                <x-input-error id="password-error" :messages="$passwordErrors->all()" class="mt-1.5" />
            </div>

            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
                <label for="remember" class="flex min-h-11 cursor-pointer items-center gap-2.5 text-sm text-slate-700">
                    <input id="remember" name="remember" type="checkbox" value="1"
                           class="h-4 w-4 rounded border-slate-400 text-indigo-600 accent-indigo-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Keep me signed in
                </label>

                <p class="text-xs text-slate-500">Only on a device you trust.</p>
            </div>

            <button type="submit"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Log in
            </button>
        </form>

        <div class="mt-8 border-t border-slate-200 pt-6 text-center text-sm text-slate-600">
            <p>New here?</p>
            <a href="{{ route('register') }}"
               class="mt-1 inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Create an account
            </a>
        </div>
    </div>

    <script>
        /*
         * Password visibility, written as a progressive enhancement: with scripting
         * unavailable the field is still a password field and the form still works,
         * so nothing here is allowed to be required for the page to be usable.
         */
        (function () {
            var input = document.getElementById('password');
            var toggle = document.getElementById('password-toggle');

            if (!input || !toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var reveal = input.type === 'password';

                input.type = reveal ? 'text' : 'password';
                toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
                toggle.querySelector('[data-password-label]').textContent = reveal ? 'Hide password' : 'Show password';
                toggle.querySelector('[data-password-icon="show"]').classList.toggle('hidden', reveal);
                toggle.querySelector('[data-password-icon="hide"]').classList.toggle('hidden', !reveal);

                // Keep the caret where it was rather than jumping to the start.
                var end = input.value.length;

                input.focus();

                try {
                    input.setSelectionRange(end, end);
                } catch (error) {
                    // Some input types refuse selection APIs; focus alone is enough.
                }
            });
        })();
    </script>
</x-auth-layout>