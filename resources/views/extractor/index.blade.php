<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Extractor</title>
</head>
<body>
    <h1>Extractor</h1>

    <p><a href="{{ route('extractor.create') }}">Start a new extraction</a></p>

    @if ($extractions->isEmpty())
        <p>No extractions yet.</p>
    @else
        <ul>
            @foreach ($extractions as $extraction)
                <li>
                    <a href="{{ route('extractor.show', $extraction) }}">
                        Extraction #{{ $extraction->id }} · {{ $extraction->status }}
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</body>
</html>
