@php
    /*
     * Copy on this page is product copy, and every claim in it has to be
     * something the application can actually do. Nothing here promises inbox
     * placement, address certainty or a campaign engine that does not exist
     * yet; unfinished capability is labelled instead of implied.
     */
    $workflow = [
        [
            'title' => 'Bring in contacts',
            'body' => 'Paste in text you already have, or point the extractor at a single public web page. Addresses are collected, de-duplicated, and kept against the task they came from.',
            'icon' => 'download',
        ],
        [
            'title' => 'Check them',
            'body' => 'Every address is compared against objective signals such as its format, its domain, and how its mail server answers. Those signals can show that an address cannot work; they cannot prove a mailbox is being read, and are not reported as if they could.',
            'icon' => 'check',
        ],
        [
            'title' => 'Organise your contacts',
            'body' => 'Keep the addresses you want to work with in lists, so a group can be reused instead of being rebuilt from an export every time.',
            'icon' => 'list',
        ],
        [
            'title' => 'Connect your mail account',
            'body' => 'Add the SMTP account you already send from. The connection, its encryption and its authentication are each verified, and the account can be tested with a single message before it is relied on.',
            'icon' => 'mail',
        ],
        [
            'title' => 'Check sending readiness',
            'body' => 'A readiness summary covers sender authentication, domain configuration and your sending policy, so you can see whether your setup is sound before you depend on it.',
            'icon' => 'gauge',
        ],
    ];

    $features = [
        [
            'title' => 'Email extraction',
            'body' => 'Pull addresses out of text you paste or a single public page. Each extraction keeps a record of where the addresses came from and how many were found.',
            'icon' => 'download',
        ],
        [
            'title' => 'Evidence-based checking',
            'body' => 'Results are grouped into clearly invalid, likely usable, and uncertain. An address is never reported as confirmed active, because that cannot be established from outside a mailbox.',
            'icon' => 'check',
        ],
        [
            'title' => 'Your own mail account',
            'body' => 'You connect the mail account you already own. Passwords are stored encrypted and are never shown again after you save them.',
            'icon' => 'mail',
        ],
        [
            'title' => 'Contact lists',
            'body' => 'Group the contacts you want to keep and reuse them later, with the source and check result attached to each address.',
            'icon' => 'list',
        ],
        [
            'title' => 'Controlled sending',
            'body' => 'Sending is configured to progress in a measured way, with visible limits, rather than to fire everything at once and hope.',
            'icon' => 'shield',
        ],
        [
            'title' => 'Sending readiness',
            'body' => 'Sender authentication and domain configuration are reported so you can fix a weak setup. This describes configuration only — it does not promise inbox placement.',
            'icon' => 'gauge',
        ],
    ];

    $honesty = [
        'Every result is traceable to a signal. An address is called clearly invalid only when objective checks support it.',
        'Uncertain stays uncertain. Nothing here turns an unknown result into a confident yes.',
        'Clearly invalid addresses can be filtered out before you send anything.',
        'You stay in control of your mail account, and credentials are never displayed after saving.',
        'Consent and suppression records are treated as part of responsible sending, not as an afterthought.',
        'Inbox placement is not guaranteed and not promised. Sender reports configuration; the receiving inbox decides.',
    ];

    $upcoming = [
        ['title' => 'Scheduled campaigns', 'body' => 'Compose, schedule and send a campaign from a saved list.'],
        ['title' => 'Delivery reporting', 'body' => 'Per-recipient delivery outcomes and a simple performance summary.'],
        ['title' => 'Bulk file uploads', 'body' => 'Import a spreadsheet instead of pasting content by hand.'],
        ['title' => 'Team workspaces', 'body' => 'Share lists and sending activity across an organisation.'],
    ];

    $icons = [
        'download' => '<path d="M12 3.5v10m0 0 4-4m-4 4-4-4"/><path d="M4.5 16.5v1.75a2.25 2.25 0 0 0 2.25 2.25h10.5a2.25 2.25 0 0 0 2.25-2.25V16.5"/>',
        'check' => '<path d="M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18Z"/><path d="m8.75 12.25 2.25 2.25 4.25-4.5"/>',
        'list' => '<path d="M8.5 7h11M8.5 12h11M8.5 17h11"/><path d="M4.25 7h.01M4.25 12h.01M4.25 17h.01"/>',
        'mail' => '<rect x="2.75" y="5" width="18.5" height="14" rx="2.5"/><path d="m3.75 7.5 7.36 5.26a2 2 0 0 0 2.28 0l7.36-5.26"/>',
        'gauge' => '<path d="M4.5 18a8.5 8.5 0 1 1 15 0"/><path d="m12 14.5 3.5-3.5"/><circle cx="12" cy="18" r="1.25"/>',
        'shield' => '<path d="M12 3.25 5 6v5.5c0 4 2.9 7.4 7 9.25 4.1-1.85 7-5.25 7-9.25V6l-7-2.75Z"/><path d="m9.25 12.25 2 2 3.5-3.75"/>',
    ];
@endphp

<x-public-layout title="Extract, check and prepare email contacts">
    {{-- Hero --}}
    <section class="border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8 lg:py-24">
            <div class="max-w-3xl">
                <p class="inline-flex items-center gap-2 rounded-full border border-indigo-200 bg-indigo-50 px-3 py-1 text-xs font-medium text-indigo-700">
                    <span class="h-1.5 w-1.5 rounded-full bg-indigo-600" aria-hidden="true"></span>
                    {{ config('app.name') }} · contact workspace
                </p>

                <h1 class="mt-5 text-3xl font-semibold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl lg:leading-[1.1]">
                    Extract, check and prepare email contacts in one place.
                </h1>

                <p class="mt-5 text-base leading-7 text-slate-600 sm:text-lg sm:leading-8">
                    Collect email addresses from text or a public web page, check them with
                    evidence-based checks, keep the usable ones in lists, and connect your own
                    mail account — with the state of every step visible to you while it runs.
                </p>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-center">
                    @auth
                        <a href="{{ route('dashboard') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Open workspace
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M5 12h13m0 0-5-5m5 5-5 5"/>
                            </svg>
                        </a>
                    @else
                        <a href="{{ route('register') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Get started free
                        </a>
                        <a href="{{ route('login') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Log in
                        </a>
                    @endauth
                </div>

                <p class="mt-4 text-xs leading-5 text-slate-500">
                    No credit card required. You bring your own mail account.
                </p>
            </div>

            <ul class="mt-12 grid gap-4 sm:grid-cols-3">
                @foreach ([
                    ['title' => 'Your mail, your account', 'body' => 'Sending happens through the mail account you already own.'],
                    ['title' => 'Honest results', 'body' => 'Unknown is reported as unknown instead of being rounded up to valid.'],
                    ['title' => 'No delivery promises', 'body' => 'Sender reports configuration. Inbox placement is never guaranteed.'],
                ] as $point)
                    <li class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-sm font-semibold text-slate-900">{{ $point['title'] }}</p>
                        <p class="mt-1 text-sm leading-6 text-slate-600">{{ $point['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- How it works --}}
    <section id="workflow" class="scroll-mt-24 border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    How it works
                </h2>
                <p class="mt-3 text-base leading-7 text-slate-600">
                    Five steps, in the order you would do them by hand. Nothing happens in the
                    background that you cannot see.
                </p>
            </div>

            <ol class="mt-10 space-y-4 sm:grid sm:grid-cols-2 sm:gap-4 sm:space-y-0 lg:grid-cols-3">
                @foreach ($workflow as $index => $step)
                    <li class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-indigo-50 text-indigo-700">
                                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    {!! $icons[$step['icon']] !!}
                                </svg>
                            </span>
                            <span class="text-xs font-semibold uppercase tracking-wide text-slate-400">
                                Step {{ $index + 1 }}
                            </span>
                        </div>

                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $step['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $step['body'] }}</p>
                    </li>
                @endforeach
            </ol>
        </div>
    </section>

    {{-- Features --}}
    <section id="features" class="scroll-mt-24 border-b border-slate-200 bg-white">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    What the workspace does
                </h2>
                <p class="mt-3 text-base leading-7 text-slate-600">
                    The capabilities that exist today, described the way a person using them
                    would describe them.
                </p>
            </div>

            <div class="mt-10 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($features as $feature)
                    <div class="flex h-full flex-col rounded-xl border border-slate-200 bg-white p-5">
                        <span class="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-slate-100 text-slate-700">
                            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                {!! $icons[$feature['icon']] !!}
                            </svg>
                        </span>
                        <h3 class="mt-4 text-base font-semibold text-slate-900">{{ $feature['title'] }}</h3>
                        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $feature['body'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Responsible sending --}}
    <section id="responsible" class="scroll-mt-24 border-b border-slate-200 bg-slate-900">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div class="grid gap-10 lg:grid-cols-2 lg:gap-16">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">
                        Responsible sending, stated plainly
                    </h2>
                    <p class="mt-4 text-base leading-7 text-slate-300">
                        Most email tools overstate what they know. This one does not, because a
                        false promise about an address is worse than an honest unknown.
                    </p>
                    <p class="mt-4 text-sm leading-6 text-slate-400">
                        Every check here works from the outside: what can be observed about an
                        address and the domain behind it. Nothing here inspects a mailbox.
                    </p>
                </div>

                <ul class="space-y-4">
                    @foreach ($honesty as $point)
                        <li class="flex gap-3 rounded-xl border border-slate-700/70 bg-slate-800/60 p-4">
                            <svg class="mt-0.5 h-5 w-5 shrink-0 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="m5 12.5 4.5 4.5L19 7.5"/>
                            </svg>
                            <span class="text-sm leading-6 text-slate-200">{{ $point }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </section>

    {{-- Not finished yet --}}
    <section class="border-b border-slate-200 bg-slate-50">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-16 lg:px-8">
            <div class="max-w-2xl">
                <h2 class="text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    Still being built
                </h2>
                <p class="mt-3 text-base leading-7 text-slate-600">
                    These are planned. They are listed here as coming soon rather than presented
                    as though they already work.
                </p>
            </div>

            <ul class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($upcoming as $item)
                    <li class="rounded-xl border border-dashed border-slate-300 bg-white p-5">
                        <p class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-1 text-xs font-medium text-amber-800">
                            Coming soon
                        </p>
                        <h3 class="mt-3 text-sm font-semibold text-slate-900">{{ $item['title'] }}</h3>
                        <p class="mt-1.5 text-sm leading-6 text-slate-600">{{ $item['body'] }}</p>
                    </li>
                @endforeach
            </ul>
        </div>
    </section>

    {{-- Closing call to action --}}
    <section class="bg-white">
        <div class="mx-auto max-w-6xl px-4 py-14 sm:px-6 sm:py-20 lg:px-8">
            <div class="rounded-2xl border border-slate-200 bg-slate-50 px-6 py-10 text-center sm:px-12 sm:py-14">
                <h2 class="mx-auto max-w-2xl text-2xl font-semibold tracking-tight text-slate-900 sm:text-3xl">
                    Ready to bring your email workflow into one place?
                </h2>
                <p class="mx-auto mt-4 max-w-xl text-base leading-7 text-slate-600">
                    Create an account and start with an extraction, or connect your mail account
                    and check whether your sender setup is sound.
                </p>

                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    @auth
                        <a href="{{ route('dashboard') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Open workspace
                        </a>
                    @else
                        <a href="{{ route('register') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center rounded-lg bg-indigo-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            Create your account
                        </a>
                        <a href="{{ route('login') }}"
                           class="inline-flex min-h-12 w-full items-center justify-center rounded-lg border border-slate-300 bg-white px-6 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 focus-visible:ring-offset-2 sm:w-auto">
                            I already have an account
                        </a>
                    @endauth
                </div>
            </div>
        </div>
    </section>
</x-public-layout>
