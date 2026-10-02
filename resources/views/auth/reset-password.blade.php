@php
    /*
     * A rejected reset arrives in two different shapes and they need different
     * treatment.
     *
     * The application attaches the outcome of the reset attempt itself to the
     * email field. That message is about the reset link, not about the address
     * the visitor typed, so showing it under the input would blame the field for
     * something the field did not do. Those messages are recognised here by the
     * translations they come from and moved above the form, with a way to request
     * a new link, because a visitor holding an expired link cannot fix anything
     * about their email address.
     *
     * Everything else stays with the field that caused it. The text rendered is
     * always the application's own human-readable wording; no message is rewritten
     * here, because rewording a security outcome is how it stops matching what the
     * application actually did.
     */
    $resetOutcomeMessages = array_values(array_filter([
        __('passwords.token'),
        __('passwords.user'),
        __('passwords.throttled'),
    ]));

    $isResetOutcome = static fn (?string $message): bool => $message !== null
        && in_array($message, $resetOutcomeMessages, true);

    $emailFieldErrors = collect($errors->get('email'))->reject($isResetOutcome)->values();
    $resetOutcomeErrors = collect($errors->get('email'))->filter($isResetOutcome)->values();
@endphp

<x-auth-layout title="Reset password">
    <x-slot:aside>
        @foreach ([
            'The link in your message brought you here, and it stays usable for a short time.',
            'Choose a password you have not used before and do not reuse elsewhere.',
            'Once your password is reset, you will sign in again with the new one.',
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
            Choose a new password
        </h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">
            Set a new password for your account, then sign in again with it.
        </p>

        @if ($resetOutcomeErrors->isNotEmpty())
            <div role="alert" class="mt-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                <div class="flex gap-3">
                    <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <circle cx="12" cy="12" r="9"/>
                        <path d="M12 8v4.5M12 16h.01"/>
                    </svg>
                    <ul class="space-y-1">
                        @foreach ($resetOutcomeErrors as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>

                <div class="mt-3 border-t border-amber-200 pt-3">
                    <p>The link may have expired. Request a new one and try again.</p>
                    <a href="{{ route('password.request') }}"
                       class="mt-2 inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Request a new reset link
                    </a>
                </div>
            </div>
        @endif

        <form method="POST" action="{{ route('password.store') }}" class="mt-6 space-y-5">
            @csrf

            {{-- The reset token travels back to the application and is never shown. --}}
            <input type="hidden" name="token" value="{{ $token }}">

            <div>
                <label for="email" class="block text-sm font-medium text-slate-800">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email', $email) }}"
                       autocomplete="username" inputmode="email" spellcheck="false" required autofocus
                       @if ($emailFieldErrors->isNotEmpty()) aria-invalid="true" @endif
                       aria-describedby="email-help{{ $emailFieldErrors->isNotEmpty() ? ' email-error' : '' }}"
                       class="mt-1.5 block min-h-12 w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                              {{ $emailFieldErrors->isNotEmpty() ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">
                <p id="email-help" class="mt-1.5 text-xs leading-5 text-slate-500">
                    The address you use to sign in.
                </p>
                <x-input-error id="email-error" :messages="$emailFieldErrors->all()" class="mt-1.5" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-800">New password</label>
                <div class="relative mt-1.5">
                    <input id="password" name="password" type="password"
                           autocomplete="new-password" required
                           aria-describedby="password-help{{ $errors->has('password') ? ' password-error' : '' }}"
                           @if ($errors->has('password')) aria-invalid="true" @endif
                           class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                  {{ $errors->has('password') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

                    <button type="button" data-password-toggle aria-controls="password" aria-pressed="false"
                            data-show-label="Show password" data-hide-label="Hide password"
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

                <p id="password-help" class="mt-1.5 text-xs leading-5 text-slate-500">
                    Use a password you do not reuse anywhere else.
                </p>

                <x-input-error id="password-error" :messages="$errors->get('password')" class="mt-1.5" />
            </div>

            <div>
                <label for="password_confirmation" class="block text-sm font-medium text-slate-800">Confirm new password</label>
                <div class="relative mt-1.5">
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           autocomplete="new-password" required
                           aria-describedby="password_confirmation-help{{ $errors->has('password_confirmation') ? ' password_confirmation-error' : '' }}"
                           @if ($errors->has('password_confirmation')) aria-invalid="true" @endif
                           class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                  {{ $errors->has('password_confirmation') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

                    <button type="button" data-password-toggle aria-controls="password_confirmation" aria-pressed="false"
                            data-show-label="Show password confirmation" data-hide-label="Hide password confirmation"
                            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-lg text-slate-500 transition hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">
                        <span data-password-label class="sr-only">Show password confirmation</span>
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

                <p id="password_confirmation-help" class="mt-1.5 text-xs leading-5 text-slate-500">
                    Enter the same password again to confirm it.
                </p>

                <x-input-error id="password_confirmation-error" :messages="$errors->get('password_confirmation')" class="mt-1.5" />
            </div>

            <button type="submit"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Reset password
            </button>
        </form>

        <p class="mt-4 text-center text-xs leading-5 text-slate-500">
            After you reset your password, you will be asked to sign in again with the new one.
        </p>

        <div class="mt-6 space-y-3 border-t border-slate-200 pt-6 text-center text-sm">
            <p class="text-slate-600">
                <a href="{{ route('login') }}"
                   class="inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Back to sign in
                </a>
            </p>

            <p class="text-slate-600">
                Need a new reset link?
                <a href="{{ route('password.request') }}"
                   class="inline-flex min-h-11 items-center font-medium text-slate-700 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Request a new one
                </a>
            </p>
        </div>
    </div>
</x-auth-layout>