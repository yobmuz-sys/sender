@php
    // Computed here rather than inside a `@if`: a closure in a directive
    // argument is Blade's parser problem, not this page's. Compared by value so
    // the view needs no reference to the enum itself.
    $usesUnsubscribeToken = collect($usedTokens)->contains('unsubscribe_url');
@endphp

<x-layout>
    <x-slot:title>{{ $template->name }}</x-slot:title>

    <x-page-header :title="$template->name" description="A reusable message. This page shows exactly what a recipient would be sent.">
        <x-slot:actions>
            <x-button :href="route('templates.edit', $template)">Edit</x-button>

            <form method="POST" action="{{ route('templates.duplicate', $template) }}">
                @csrf
                <x-button variant="secondary" type="submit">Copy</x-button>
            </form>

            <x-button variant="secondary" :href="route('templates.index')">All templates</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($blockers !== [])
        <x-alert variant="warning" title="This template cannot be sent yet" class="mb-6">
            <ul class="mt-1 list-disc space-y-1 pl-5">
                @foreach ($blockers as $blocker)
                    <li>{{ $blocker }}</li>
                @endforeach
            </ul>

            <p class="mt-2">
                <a href="{{ route('templates.edit', $template) }}" class="underline">Edit this template</a>
                to fix them.
            </p>
        </x-alert>
    @endif

    <div class="space-y-6">
        <x-card>
            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Status</dt>
                    <dd class="mt-1">
                        <x-status-badge :status="$status->value" :label="$status->label()" />
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Version</dt>
                    <dd class="mt-1 text-sm text-slate-800">
                        {{ $template->version }}
                        <span class="block text-xs text-slate-500">
                            Campaigns copy this content when they start, so a later edit does not change
                            what they send.
                        </span>
                    </dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Created</dt>
                    <dd class="mt-1 text-sm text-slate-800">{{ $template->created_at->format('j M Y') }}</dd>
                </div>

                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Last edited</dt>
                    <dd class="mt-1 text-sm text-slate-800">{{ $template->updated_at->diffForHumans() }}</dd>
                </div>
            </dl>
        </x-card>

        <x-card title="Subject"
                description="What a recipient sees in their inbox list, and what a spam filter reads first.">
            <p class="text-sm text-slate-800">{{ $template->subject }}</p>

            @if ($template->preheader)
                <p class="mt-3 text-xs uppercase tracking-wide text-slate-500">Preheader</p>
                <p class="mt-1 text-sm text-slate-600">{{ $template->preheader }}</p>
            @endif
        </x-card>

        <x-card title="Preview"
                description="Example values stand in for the recipient's details. When a campaign sends, each recipient's own address, name and unsubscribe link are filled in.">
            {{--
                The sandbox is the security boundary, not the stripping above it: with
                no allowances listed, this frame cannot run scripts, submit forms or
                reach this page, whatever the customer's markup contains. The markup
                was also stripped before it got here, so what is shown is readable.
            --}}
            <iframe title="{{ $template->name }} preview"
                    sandbox
                    class="block h-96 w-full rounded-md border border-slate-200 bg-white"
                    srcdoc="{{ $previewHtml }}"></iframe>

            <p class="mt-3 text-xs text-slate-500">
                Shown in a locked frame: no scripts run, and the preview cannot load or contact anything.
            </p>
        </x-card>

        <x-card title="Plain-text version"
                description="What a recipient sees if their mail app cannot display HTML.">
            <pre class="overflow-x-auto whitespace-pre-wrap break-words font-mono text-xs text-slate-700">{{ $previewText }}</pre>
        </x-card>

        <x-card title="Personalisation"
                description="The placeholders this template uses, and every placeholder it may use.">
            @if ($usedTokens === [])
                <p class="text-sm text-slate-600">
                    This message is the same for everyone. It uses no placeholders.
                </p>
            @else
                <ul class="flex flex-wrap gap-2">
                    @foreach ($usedTokens as $token)
                        <li>
                            <code class="rounded bg-slate-100 px-2 py-1 font-mono text-xs text-slate-800">
                                {{ $token->placeholder() }}
                            </code>
                        </li>
                    @endforeach
                </ul>
            @endif

            <ul class="mt-4 divide-y divide-slate-100">
                @foreach ($tokens as $token)
                    <li class="py-2 text-sm">
                        <code class="font-mono text-slate-900">{{ $token->placeholder() }}</code>
                        <span class="text-slate-700">{{ $token->label() }}.</span>
                        <span class="text-xs text-slate-500">{{ $token->description() }}</span>
                    </li>
                @endforeach
            </ul>

            <p class="mt-4 text-sm text-slate-600">
                Campaigns require a working unsubscribe link, supplied per recipient by the platform.
                @unless ($usesUnsubscribeToken)
                    <span class="text-amber-800">
                        This template does not currently include
                        <code class="font-mono">@{{unsubscribe_url}}</code>.
                    </span>
                @endunless
            </p>
        </x-card>

        <x-card title="Using this template">
            <p class="text-sm text-slate-600">
                Choosing a template for a list of recipients is what campaigns do, and campaign sending
                is not available yet. When it is, this template will be ready to use — as long as the
                checks above pass.
            </p>
        </x-card>
    </div>
</x-layout>