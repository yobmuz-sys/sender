@php
    $user = auth()->user();
    $isConfirmed = $user->hasVerifiedEmail();
    $firstName = \Illuminate\Support\Str::before((string) $user->name, ' ') ?: 'there';

    /*
     * Recent work, and only this account's work.
     *
     * The dashboard route is a plain view, so the query lives here rather than in
     * a controller built for it. It is a single indexed read of five rows, scoped
     * by owner and limited at the database, because a home screen that cannot show
     * the work someone actually did is a worse home screen than one without it.
     * No count is invented: every number below is a column the extractor already
     * writes.
     */
    $recentExtractions = $isConfirmed
        ? \App\Models\Extraction::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(5)
            ->get(['id', 'name', 'source_type', 'status', 'found_count', 'created_at'])
        : collect();

    $sourceLabels = ['paste' => 'Pasted text', 'url' => 'Web page'];
@endphp

<x-layout>
    <x-slot:title>Dashboard</x-slot:title>

    <x-page-header
        title="Welcome back, {{ $firstName }}"
        description="Here is what you can do from your workspace."
    />

    @unless ($isConfirmed)
        <x-alert variant="warning" title="Confirm your email address" class="mb-6">
            <p>
                Confirm your email address to unlock the parts of your workspace that need a
                confirmed account.
            </p>
            <p class="mt-3">
                <a href="{{ route('verification.notice') }}"
                   class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Confirm your email
                </a>
            </p>
        </x-alert>
    @endunless

    @if ($isConfirmed)
        <section aria-labelledby="actions-heading">
            <h2 id="actions-heading" class="text-base font-semibold text-slate-900">
                What you can do now
            </h2>

            <div class="mt-4 grid gap-4 md:grid-cols-3">
                <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-900">Extract email addresses</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">
                        Paste content you already have, or point the extractor at a single public
                        web page, then read what it found.
                    </p>

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="{{ route('extractor.create') }}"
                           class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            New extraction
                        </a>
                        <a href="{{ route('extractor.history') }}"
                           class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            View extraction history
                        </a>
                    </div>
                </div>

                <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-900">Connect your mail account</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">
                        Add the mail account you already use and have its connection verified,
                        before anything else relies on it.
                    </p>

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="{{ route('account.smtp.create') }}"
                           class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Set up SMTP
                        </a>
                        <a href="{{ route('account.smtp.index') }}"
                           class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            Mail transports
                        </a>
                    </div>
                </div>

                <div class="flex flex-col rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                    <h3 class="text-base font-semibold text-slate-900">Check sender readiness</h3>
                    <p class="mt-2 flex-1 text-sm leading-6 text-slate-600">
                        Review the checks that decide whether your mail setup is ready for future
                        sending. Configuration only, not a delivery promise.
                    </p>

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <a href="{{ route('account.deliverability.index') }}"
                           class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                            View sending health
                        </a>
                    </div>
                </div>
            </div>
        </section>
    @else
        <section aria-labelledby="pending-heading">
            <h2 id="pending-heading" class="text-base font-semibold text-slate-900">
                Next step
            </h2>

            <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-sm leading-6 text-slate-600">
                    Extracting addresses, adding a mail account and checking sending health all
                    open once your email address is confirmed. In the meantime you can update your
                    details and change your password.
                </p>

                <div class="mt-5 flex flex-wrap items-center gap-3">
                    <a href="{{ route('verification.notice') }}"
                       class="inline-flex min-h-11 items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Confirm your email
                    </a>
                    <a href="{{ route('account.profile') }}"
                       class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Update your profile
                    </a>
                    <a href="{{ route('account.security') }}"
                       class="inline-flex min-h-11 items-center text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Change your password
                    </a>
                </div>
            </div>
        </section>
    @endif

    @if ($isConfirmed)
        <section aria-labelledby="recent-heading" class="mt-8">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 id="recent-heading" class="text-base font-semibold text-slate-900">
                    Recent extractions
                </h2>
                <a href="{{ route('extractor.history') }}"
                   class="text-sm font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    See all
                </a>
            </div>

            @if ($recentExtractions->isEmpty())
                <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
                    <p class="text-sm font-medium text-slate-900">No extractions yet</p>
                    <p class="mx-auto mt-1 max-w-md text-sm leading-6 text-slate-600">
                        Start one from content you already have, and the addresses it finds will
                        appear here.
                    </p>
                    <a href="{{ route('extractor.create') }}"
                       class="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                        Start your first extraction
                    </a>
                </div>
            @else
                <ul class="mt-4 divide-y divide-slate-200 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                    @foreach ($recentExtractions as $extraction)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
                            <div class="min-w-0">
                                <a href="{{ route('extractor.show', $extraction) }}"
                                   class="block truncate text-sm font-semibold text-slate-900 underline-offset-2 hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                                    {{ $extraction->name }}
                                </a>
                                <p class="mt-0.5 text-xs text-slate-500">
                                    {{ $sourceLabels[$extraction->source_type] ?? 'Content' }}
                                    &middot;
                                    {{ $extraction->created_at?->format('j M Y, H:i') }}
                                </p>
                            </div>

                            <div class="flex items-center gap-3">
                                <span class="text-sm text-slate-600">
                                    <span class="font-medium text-slate-900">{{ number_format($extraction->found_count) }}</span>
                                    found
                                </span>
                                <x-status-badge :status="$extraction->status->tone()" :label="$extraction->status->label()" />
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    <section aria-labelledby="account-heading" class="mt-8">
        <h2 id="account-heading" class="text-base font-semibold text-slate-900">
            Your account
        </h2>

        <div class="mt-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <dl class="grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Email</dt>
                    <dd class="mt-1 break-all text-sm font-medium text-slate-900">{{ $user->email }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Account type</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->role->label() }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Member since</dt>
                    <dd class="mt-1 text-sm font-medium text-slate-900">
                        {{ $user->created_at?->format('j M Y') ?? '—' }}
                    </dd>
                </div>
            </dl>

            <div class="mt-5 flex flex-wrap items-center gap-4 border-t border-slate-200 pt-4 text-sm">
                <a href="{{ route('account.profile') }}"
                   class="inline-flex min-h-11 items-center font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Update your profile
                </a>
                <a href="{{ route('account.security') }}"
                   class="inline-flex min-h-11 items-center font-medium text-indigo-700 underline underline-offset-2 transition hover:text-indigo-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2">
                    Change your password
                </a>
            </div>
        </div>
    </section>

    <section aria-labelledby="coming-heading" class="mt-8">
        <h2 id="coming-heading" class="text-base font-semibold text-slate-900">
            More tools are coming
        </h2>

        <div class="mt-4 rounded-xl border border-dashed border-slate-300 bg-white p-5">
            <p class="text-sm leading-6 text-slate-600">
                Contact lists, message templates, campaign sending, suppression and reporting are
                still being built. Nothing here is available to use yet, and nothing on this page
                will pretend otherwise.
            </p>

            <ul class="mt-4 flex flex-wrap gap-2">
                @foreach ([
                    'Contact lists',
                    'Message templates',
                    'Campaign sending',
                    'Suppression',
                    'Reporting',
                ] as $upcoming)
                    <li class="inline-flex items-center gap-2 rounded-full border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-900">
                        <span class="font-semibold">Coming soon</span>
                        {{ $upcoming }}
                    </li>
                @endforeach
            </ul>
        </div>
    </section>
</x-layout>