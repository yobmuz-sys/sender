@php
    /*
     * The account's own details, and only those.
     *
     * Two fields is what the backend accepts, and two fields is what a customer
     * can be expected to want here. Everything else about an account — its type,
     * its standing, what it is allowed to reach — is set by someone else and shown
     * as a fact rather than as something this form could change.
     */
    $isConfirmed = $user->hasVerifiedEmail();
@endphp

<x-layout>
    <x-slot:title>Profile</x-slot:title>

    <x-page-header
        title="Profile"
        description="Manage the name and email address associated with your account."
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- An account that cannot yet use the parts of the workspace behind a
                 confirmed address is told so here, above the form, rather than
                 discovering it after filling the form in. --}}
            @unless ($isConfirmed)
                <x-alert variant="warning" title="Email not confirmed">
                    <p>
                        This account's email address has not been confirmed yet. Confirm it to
                        use the parts of the workspace that need a confirmed account.
                    </p>

                    <div class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                        <a href="{{ route('verification.notice') }}"
                           class="inline-flex min-h-11 items-center rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-amber-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                            Confirm your email
                        </a>

                        <form method="POST" action="{{ route('verification.send') }}">
                            @csrf
                            <button type="submit"
                                    class="inline-flex min-h-11 items-center rounded-lg px-2 py-2 text-sm font-medium text-amber-900 underline underline-offset-2 transition hover:text-amber-950 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:ring-offset-2">
                                Send a new confirmation link
                            </button>
                        </form>
                    </div>
                </x-alert>
            @endunless

            <x-card
                title="Personal details"
                description="How your account appears to you and to the people you send to."
            >
                <form method="POST" action="{{ route('account.profile.update') }}" class="space-y-6">
                    @csrf
                    @method('PUT')

                    {{-- Written out rather than through a component: the inputs need
                         attributes that appear only when a value is wrong, and Blade
                         cannot read a conditional inside a component tag. --}}
                    <div>
                        <x-input-label for="name">Name</x-input-label>

                        <input id="name" name="name" type="text" autocomplete="name"
                               value="{{ old('name', $user->name) }}"
                               @if ($errors->has('name'))
                                   aria-invalid="true" aria-describedby="name-error"
                                   class="mt-1 block min-h-11 w-full rounded-md border border-rose-400 bg-white px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                               @else
                                   class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                               @endif
                               required autofocus>

                        @if ($errors->has('name'))
                            <x-input-error id="name-error" :messages="$errors->get('name')" class="mt-1" />
                        @endif
                    </div>

                    <div>
                        <x-input-label for="email">Email address</x-input-label>

                        <input id="email" name="email" type="email" inputmode="email" spellcheck="false"
                               autocomplete="username"
                               value="{{ old('email', $user->email) }}"
                               @if ($errors->has('email'))
                                   aria-invalid="true" aria-describedby="email-help email-error"
                                   class="mt-1 block min-h-11 w-full rounded-md border border-rose-400 bg-white px-3 py-2 text-sm shadow-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500"
                               @else
                                   aria-describedby="email-help"
                                   class="mt-1 block min-h-11 w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-slate-500 focus:outline-none focus:ring-1 focus:ring-slate-500"
                               @endif
                               required>

                        <p id="email-help" class="mt-2 text-xs leading-5 text-slate-500">
                            Changing this address will require you to confirm the new address
                            before using features that need a confirmed account.
                        </p>

                        @if ($errors->has('email'))
                            <x-input-error id="email-error" :messages="$errors->get('email')" class="mt-1" />
                        @endif
                    </div>

                    <div class="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-slate-200 pt-5">
                        <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Save changes
                        </button>

                        <a href="{{ route('dashboard') }}"
                           class="inline-flex min-h-11 items-center text-sm font-medium text-slate-600 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Back to dashboard
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Account">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Account type</dt>
                        <dd class="mt-1 text-slate-800">{{ $user->role->label() }}</dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Email status</dt>
                        <dd class="mt-1.5">
                            @if ($isConfirmed)
                                <x-status-badge status="active" label="Confirmed" />
                            @else
                                <x-status-badge status="pending" label="Not confirmed" />
                            @endif
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-slate-500">Joined</dt>
                        <dd class="mt-1 text-slate-800">{{ $user->created_at?->format('j M Y') }}</dd>
                    </div>
                </dl>
            </x-card>

            <x-card title="Password">
                <p class="text-sm leading-6 text-slate-600">
                    Changing your password ends every other session on this account.
                </p>

                <a href="{{ route('account.security') }}"
                   class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Change password
                </a>
            </x-card>
        </div>
    </div>
</x-layout>