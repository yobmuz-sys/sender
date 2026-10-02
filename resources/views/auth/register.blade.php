@php
    /*
     * Every message on this page comes from the validation layer and is rendered
     * as its human-readable text only. Rule names, attribute names and anything
     * else the error bag happens to carry are never printed, because a rule name
     * is an implementation detail dressed up as an explanation.
     *
     * Errors are attached to the field that owns them. Anything with no field of
     * its own would otherwise have nowhere to appear, so it is collected into a
     * single notice above the form rather than being silently dropped.
     */
    $fields = ['name', 'email', 'password', 'password_confirmation'];
    $unattached = collect($errors->all())->reject(
        static fn (string $message): bool => collect($fields)->contains(
            static fn (string $field): bool => in_array($message, $errors->get($field), true),
        ),
    )->unique()->values();
@endphp

<x-auth-layout title="Create your account">
    <x-slot:aside>
        @foreach ([
            'Create your account, confirm your email address, and start work in your own workspace.',
            'Extract addresses from text or a single public page, and follow every run as it happens.',
            'Bring the SMTP account you already use, and verify it before anything depends on it.',
        ] as $point)
            <li class="flex gap-3 text-sm leading-6 text-slate-300">
                <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="m5 12.5 4.5 4.5L19 7.5"/>
                </svg>
                {{ $point }}
            </li>
        @endforeach
    </x-slot:aside>

    <x-slot:aside-note>
        <p class="mt-10 text-xs leading-5 text-slate-400">
            Contact lists and campaign sending are still being built.
        </p>
    </x-slot:aside-note>

    <div class="mx-auto w-full max-w-sm">
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
            Create your account
        </h1>
        <p class="mt-2 text-sm leading-6 text-slate-600">
            Set up your account to start extracting and checking email contacts.
        </p>

        @if ($unattached->isNotEmpty())
            <div role="alert" class="mt-6 flex gap-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-900">
                <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <circle cx="12" cy="12" r="9"/>
                    <path d="M12 8v4.5M12 16h.01"/>
                </svg>
                <ul class="space-y-1">
                    @foreach ($unattached as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-5">
            @csrf

            <div>
                <label for="name" class="block text-sm font-medium text-slate-800">Name</label>
                <input id="name" name="name" type="text" value="{{ old('name') }}"
                       autocomplete="name" spellcheck="false" required autofocus
                       @if ($errors->has('name')) aria-invalid="true" aria-describedby="name-error" @endif
                       class="mt-1.5 block min-h-12 w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                              {{ $errors->has('name') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">
                <x-input-error id="name-error" :messages="$errors->get('name')" class="mt-1.5" />
            </div>

            <div>
                <label for="email" class="block text-sm font-medium text-slate-800">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}"
                       autocomplete="username" inputmode="email" spellcheck="false" required
                       @if ($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif
                       class="mt-1.5 block min-h-12 w-full rounded-lg border bg-white px-3 py-2.5 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                              {{ $errors->has('email') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">
                <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-1.5" />
            </div>

            <div>
                <label for="password" class="block text-sm font-medium text-slate-800">Password</label>
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
                <label for="password_confirmation" class="block text-sm font-medium text-slate-800">Confirm password</label>
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
                    Enter the same password once more.
                </p>

                <x-input-error id="password_confirmation-error" :messages="$errors->get('password_confirmation')" class="mt-1.5" />
            </div>

            <button type="submit"
                    class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Create account
            </button>
        </form>

        <p class="mt-4 text-center text-xs leading-5 text-slate-500">
            After creating your account, you’ll be asked to confirm your email address.
        </p>

        <div class="mt-6 border-t border-slate-200 pt-6 text-center text-sm text-slate-600">
            <p>Already have an account?</p>
            <a href="{{ route('login') }}"
               class="mt-1 inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Log in
            </a>
        </div>
    </div>
</x-auth-layout>