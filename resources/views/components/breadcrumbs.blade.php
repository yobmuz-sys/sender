@props(['items' => []])

@if (! empty($items))
    <nav class="mb-4 text-sm text-slate-500" aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1">
            @foreach ($items as $index => $item)
                @if ($index > 0)
                    <li aria-hidden="true" class="px-1">/</li>
                @endif

                <li>
                    @if (! empty($item['route']))
                        <a href="{{ route($item['route']) }}" class="hover:text-slate-800">{{ $item['label'] }}</a>
                    @else
                        <span class="text-slate-800">{{ $item['label'] }}</span>
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@endif
@end