@props(['label', 'value', 'hint' => null])

<div class="rounded-lg border border-slate-200 bg-white px-4 py-4 shadow-sm">
    <p class="text-xs uppercase tracking-wide text-slate-500">{{ $label }}</p>
    <p class="mt-1 text-2xl font-semibold text-slate-900">{{ $value }}</p>
    @isset($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endisset
</div>
@end