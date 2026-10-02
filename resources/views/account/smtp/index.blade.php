@php
    /*
     * Presentation only.
     *
     * The states are the application's own: this page shows the label the domain
     * already produces and never collapses it into a good/bad binary, because
     * "Verification expired" and "Never verified" ask a reader different
     * questions. The tone is chosen here because colour is presentation, and the
     * word beside it is what actually says what is going on.
     */
    $statusTones = [
        'ready' => 'READY',
        'stale' => 'DEGRADED',
        'failed' => 'failed',
        'disabled' => 'suspended',
        'unverified' => 'pending',
    ];

    /*
     * Who is responsible for the account, said in words rather than in terms of the
     * two values behind it. An account the platform manages is read-only here,
     * which the account page explains; this only names who may change it.
     */
    $managementLabels = [
        'user_managed' => 'Managed by you',
        'admin_managed' => 'Managed by platform',
    ];
@endphp

<x-layout>
    <x-slot:title>Mail accounts</x-slot:title>

    <x-page-header
        title="Mail accounts"
        description="Connect and verify the mail account you plan to use with your email workflow."
    >
        <x-slot:actions>
            <a href="{{ route('account.smtp.create') }}"
               class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Add mail account
            </a>
        </x-slot:actions>
    </x-page-header>

    <section aria-labelledby="about-mail-accounts" class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 id="about-mail-accounts" class="text-base font-semibold text-slate-900">
            What verifying an account does
        </h2>

        <p class="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Verifying checks that the mail service can be reached and, where the provider requires
            it, that the account can authenticate and accept a message. It does not test inbox
            placement or whether a message is treated as spam.
        </p>

        <p class="mt-3 max-w-3xl text-sm leading-6 text-slate-600">
            Add and verify your mail account now so it is set up for the sending features.
            Campaign sending is still being built, so nothing sends on your behalf yet.
        </p>

        <div class="mt-4 border-t border-slate-200 pt-4">
            <a href="{{ route('account.deliverability.index') }}"
               class="inline-flex min-h-11 items-center font-semibold text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                Sending health
            </a>
            <p class="text-sm text-slate-600">
                Review sender-readiness checks for your mail setup.
            </p>
        </div>
    </section>

    @forelse ($accounts as $account)
        @php
            $status = $account->effectiveStatus();
            $canEdit = $account->ownerMayEdit();
        @endphp

        <article aria-labelledby="mail-account-{{ $account->getKey() }}"
                 class="mt-6 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <h3 id="mail-account-{{ $account->getKey() }}" class="text-base font-semibold text-slate-900">
                            <a href="{{ route('account.smtp.show', $account) }}"
                               class="rounded transition hover:text-indigo-700 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                                {{ $account->label }}
                            </a>
                        </h3>

                        {{-- The badge carries the wording; the colour only reinforces it. --}}
                        <x-status-badge
                            :status="$statusTones[$status->value] ?? 'UNKNOWN'"
                            :label="$status->label()"
                        />
                    </div>

                    <dl class="mt-3 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-slate-500">Provider</dt>
                            <dd class="mt-0.5 text-slate-900">{{ $account->provider->label() }}</dd>
                        </div>

                        <div class="min-w-0">
                            <dt class="text-slate-500">Sends as</dt>
                            <dd class="mt-0.5 break-all text-slate-900">{{ $account->from_address }}</dd>
                        </div>
                    </dl>

                    <p class="mt-3 text-xs text-slate-500">
                        {{ $managementLabels[$account->management_mode->value] ?? 'Managed by you' }}
                        @unless ($canEdit)
                            · Contact platform support to change this account
                        @endunless
                    </p>
                </div>

                <div class="flex shrink-0 flex-col items-start gap-1 sm:items-end">
                    <a href="{{ route('account.smtp.show', $account) }}"
                       class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        View account
                    </a>

                    @if ($canEdit)
                        <a href="{{ route('account.smtp.edit', $account) }}"
                           class="inline-flex min-h-11 items-center px-4 py-2 text-sm font-medium text-slate-600 underline underline-offset-2 transition hover:text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Edit
                        </a>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="mt-6 rounded-xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center shadow-sm">
            <h2 class="text-base font-semibold text-slate-900">No mail accounts yet</h2>

            <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">
                Add a mail account to connect the service you plan to use for email. You can
                verify it before anything depends on it.
            </p>

            <p class="mt-6">
                <a href="{{ route('account.smtp.create') }}"
                   class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Add mail account
                </a>
            </p>
        </div>
    @endforelse
</x-layout>