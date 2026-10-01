<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extraction #{{ $extraction->id }}</title>
</head>
<body>
    <h1>Extraction #{{ $extraction->id }}</h1>

    <p>Status: {{ $extraction->status }}</p>
    <p>Found: {{ $extraction->found_count }}</p>

    @if ($extraction->results->isNotEmpty())
        <ul>
            @foreach ($extraction->results as $result)
                <li>{{ $result->email }}</li>
            @endforeach
        </ul>
    @else
        <p>No addresses found yet.</p>
    @endif

    <p><a href="{{ route('extractor.index') }}">Back to extractions</a></p>
    <p><a href="{{ route('extractor.download', $extraction) }}">Download</a></p>
</body>
</html>
