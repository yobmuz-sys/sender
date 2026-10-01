@props(['title', 'description' => null])

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <h1 class="text-2xl font-semibold text-slate-900">{{ $title }}</h1>
        @isset($description)
            <p class="mt-1 max-w-2xl text-sm text-slate-600">{{ $description }}</p>
        @endisset
    </div>

    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
@end