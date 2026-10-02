@props(['title' => null, 'description' => null])

<section {{ $attributes->class('rounded-lg border border-slate-200 bg-white shadow-sm') }}>
    @if ($title)
        <header class="border-b border-slate-200 px-5 py-4">
            <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
            @isset($description)
                <p class="mt-1 text-sm text-slate-600">{{ $description }}</p>
            @endisset
        </header>
    @endif

    <div class="px-5 py-4">{{ $slot }}</div>
</section>
