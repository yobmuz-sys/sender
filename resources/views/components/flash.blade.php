{{--
    Session flash messages.

    Any message that names a state the reader may not be allowed to act on is
    deliberately not trusted: it is rendered as text, never as a link.
--}}

@foreach ([
    'status' => 'success',
    'success' => 'success',
    'error' => 'danger',
    'warning' => 'warning',
    'info' => 'info',
] as $key => $variant)
    @if (session()->has($key))
        <x-alert variant="{{ $variant }}" class="mb-4">{{ session($key) }}</x-alert>
    @endif
@endforeach

@if ($errors->any())
    <x-alert variant="danger" title="There is a problem with this form" class="mb-4">
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </x-alert>
@endif
