@php
    /*
     * The application's own validation wording is rendered as it is written.
     *
     * One exception, and it is a placement decision rather than a rewording: the
     * framework records a confirmation mismatch against the password field,
     * because that is the field it compares. The visitor has to fix the
     * confirmation, so the message is recognised here by the translation it comes
     * from and moved under the field it belongs to — the same treatment the reset
     * screen gives its own misplaced messages. The text itself is untouched:
     * rewording a security outcome is how it stops matching what the application
     * actually did.
     */
    $mismatchMessage = __('validation.confirmed', ['attribute' => 'password']);

    $isMismatch = static fn (?string $message): bool => $message === $mismatchMessage;

    $passwordErrors = collect($errors->get('password'))->reject($isMismatch)->values();

    $confirmationErrors = collect($errors->get('password'))
        ->filter($isMismatch)
        ->merge(collect($errors->get('password_confirmation')))
        ->values();
@endphp

<x-layout>
    <x-slot:title>Security</x-slot:title>

    <x-page-header
        title="Security"
        description="Change your account password and keep your sign-in details secure."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2">
            <x-card title="Change password">
                <p class="mb-6 text-sm leading-6 text-slate-600">
                    Confirm your current password, then choose a new one. Nothing about your
                    account changes until you save it.
                </p>

                {{-- No field is given a value, before or after a rejected submission: a
                     password that came back into the page would already have been read. --}}
                <form method="POST" action="{{ route('account.password.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    <div>
                        <x-input-label for="current_password">Current password</x-input-label>

                        <div class="relative mt-1.5">
                            <input id="current_password" name="current_password" type="password"
                                   autocomplete="current-password"
                                   aria-describedby="current_password-help{{ $errors->has('current_password') ? ' current_password-error' : '' }}"
                                   @if ($errors->has('current_password')) aria-invalid="true" @endif
                                   required
                                   class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                          {{ $errors->has('current_password') ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

                            <button type="button" data-password-toggle aria-controls="current_password" aria-pressed="false"
                                    data-show-label="Show current password" data-hide-label="Hide current password"
                                    class="absolute inset-y-0 right-0 flex w-12 items-center justify-center rounded-r-lg text-slate-500 transition hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-indigo-500">
                                <span data-password-label class="sr-only">Show current password</span>
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

                        <p id="current_password-help" class="mt-1.5 text-xs leading-5 text-slate-500">
                            The password you sign in with.
                        </p>

                        <x-input-error id="current_password-error" :messages="$errors->get('current_password')" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="password">New password</x-input-label>

                        <div class="relative mt-1.5">
                            <input id="password" name="password" type="password"
                                   autocomplete="new-password"
                                   aria-describedby="password-help{{ $passwordErrors->isNotEmpty() ? ' password-error' : '' }}"
                                   @if ($passwordErrors->isNotEmpty()) aria-invalid="true" @endif
                                   required
                                   class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                          {{ $passwordErrors->isNotEmpty() ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

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
                            Choose a password you have not used for this account, and do not reuse
                            one you use anywhere else.
                        </p>

                        <x-input-error id="password-error" :messages="$passwordErrors->all()" class="mt-1.5" />
                    </div>

                    <div>
                        <x-input-label for="password_confirmation">Confirm new password</x-input-label>

                        <div class="relative mt-1.5">
                            <input id="password_confirmation" name="password_confirmation" type="password"
                                   autocomplete="new-password"
                                   aria-describedby="password_confirmation-help{{ $confirmationErrors->isNotEmpty() ? ' password_confirmation-error' : '' }}"
                                   @if ($confirmationErrors->isNotEmpty()) aria-invalid="true" @endif
                                   required
                                   class="block min-h-12 w-full rounded-lg border bg-white py-2.5 pl-3 pr-12 text-base text-slate-900 shadow-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-1
                                          {{ $confirmationErrors->isNotEmpty() ? 'border-rose-400 focus:border-rose-500 focus-visible:ring-rose-500' : 'border-slate-300 focus:border-indigo-500 focus-visible:ring-indigo-500' }}">

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
                            Enter the new password again to confirm it.
                        </p>

                        <x-input-error id="password_confirmation-error" :messages="$confirmationErrors->all()" class="mt-1.5" />
                    </div>

                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 pt-5">
                        <button type="submit"
                                class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Change password
                        </button>

                        <a href="{{ route('account.profile') }}"
                           class="inline-flex min-h-11 items-center text-sm font-medium text-slate-600 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Back to profile
                        </a>

                        <a href="{{ route('dashboard') }}"
                           class="inline-flex min-h-11 items-center text-sm font-medium text-slate-600 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Back to dashboard
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div>
            <x-card title="Password security">
                <ul class="space-y-3 text-sm leading-6 text-slate-600">
                    <li class="flex gap-3">
                        <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m5 12.5 4.5 4.5L19 7.5"/>
                        </svg>
                        Use a password you do not reuse anywhere else.
                    </li>
                    <li class="flex gap-3">
                        <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m5 12.5 4.5 4.5L19 7.5"/>
                        </svg>
                        Never share your password, and do not send it to anyone who asks for it.
                    </li>
                    <li class="flex gap-3">
                        <svg class="mt-1 h-4 w-4 shrink-0 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <path d="m5 12.5 4.5 4.5L19 7.5"/>
                        </svg>
                        You stay signed in here after a change. Sign out on any device you share.
                    </li>
                </ul>

                <p class="mt-5 border-t border-slate-200 pt-4 text-sm text-slate-600">
                    Signed in as
                    <span class="mt-1 block break-all font-medium text-slate-900">{{ $user->email }}</span>
                </p>
            </x-card>
        </div>
    </div>
</x-layout>