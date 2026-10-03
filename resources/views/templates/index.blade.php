<x-layout>
    <x-slot:title>Templates</x-slot:title>

    <x-page-header
        title="Templates"
        description="Reusable message content. A template holds the words of an email — subject, HTML and plain text — and is not itself a campaign: nothing sends until a campaign is built from it."
    >
        <x-slot:actions>
            <x-button :href="route('templates.create')">New template</x-button>
        </x-slot:actions>
    </x-page-header>

    @if ($templates->isEmpty())
        <x-empty-state
            title="No templates yet"
            description="A template is the reusable part of a campaign: the subject line and the message itself. Create one and it will be ready to use when campaign sending arrives."
        >
            <x-slot:actions>
                <x-button :href="route('templates.create')">New template</x-button>
            </x-slot:actions>
        </x-empty-state>
    @else
        <div class="space-y-4">
            @foreach ($templates as $template)
                <x-card>
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <h2 class="text-sm font-semibold text-slate-900">
                                <a href="{{ route('templates.show', $template) }}"
                                   class="hover:underline">{{ $template->name }}</a>
                            </h2>

                            <p class="mt-1 text-sm text-slate-600">{{ $template->subject }}</p>

                            @if ($template->preheader)
                                <p class="mt-1 text-sm text-slate-500">{{ $template->preheader }}</p>
                            @endif

                            <p class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                <x-status-badge
                                    :status="$template->effectiveStatus()->value"
                                    :label="$template->effectiveStatus()->label()"
                                />

                                <span>
                                    Version {{ $template->version }} &middot;
                                    updated {{ $template->updated_at->diffForHumans() }}
                                </span>
                            </p>
                        </div>

                        {{--
                            Copying is a POST because it creates a record. As a link
                            it would fire from a prefetch, a shared tab or an email
                            client that opens links on its own.
                        --}}
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <x-button variant="secondary" :href="route('templates.edit', $template)">Edit</x-button>

                            <form method="POST" action="{{ route('templates.duplicate', $template) }}">
                                @csrf
                                <x-button variant="secondary" type="submit">Copy</x-button>
                            </form>
                        </div>
                    </div>
                </x-card>
            @endforeach
        </div>

        <div class="mt-4">{{ $templates->links() }}</div>
    @endif
</x-layout>