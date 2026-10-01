<x-layout>
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-page-header :title="$title" :description="$description" />

    <div class="max-w-2xl">
        <x-card>
            <div class="flex items-start gap-4">
                <x-status-badge status="pending" label="Not yet available" />

                <div class="min-w-0 flex-1">
                    <p class="text-sm text-slate-700">{{ $description }}</p>

                    <p class="mt-3 text-sm text-slate-600">
                        Stage: <strong>{{ $stage }}</strong>
                    </p>

                    <p class="mt-4 text-sm font-medium text-slate-800">This section will provide</p>

                    <ul class="mt-2 space-y-1.5">
                        @foreach ($planned as $capability)
                            <li class="flex items-start gap-2 text-sm text-slate-700">
                                <span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-slate-400" aria-hidden="true"></span>
                                {{ $capability }}
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </x-card>

        <x-alert variant="info" class="mt-4">
            No records exist for this section, and none are invented. A page that displayed plausible
            looking empty tables would make it impossible to tell working functionality from a
            placeholder.
        </x-alert>
    </div>
</x-layout>
