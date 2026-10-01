@props(['title', 'description' => null])

<div {{ $attributes->class('rounded-lg border border-dashed border-slate-300 bg-slate-50 px-6 py-10 text-center') }}>
    <p class="text-sm font-medium text-slate-900">{{ $title }}</p>
    @isset($description)
        <p class="mx-auto mt-1 max-w-xl text-sm text-slate-600">{{ $description }}</p>
    @endisset
</div>
@end