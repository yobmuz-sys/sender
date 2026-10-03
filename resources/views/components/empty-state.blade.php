@props(['title', 'description' => null])

<div {{ $attributes->class('rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center') }}>
    <p class="text-sm font-medium text-slate-900">{{ $title }}</p>
    @isset($description)
        <p class="mx-auto mt-1 max-w-xl text-sm text-slate-600">{{ $description }}</p>
    @endisset

    {{--
        An empty state that explains a situation and then offers no way out of it
        is a dead end, so a caller may supply the one action that resolves it. The
        slot is optional: an empty state with nothing to offer is still honest.
    --}}
    @isset($actions)
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $actions }}</div>
    @endisset
</div>
