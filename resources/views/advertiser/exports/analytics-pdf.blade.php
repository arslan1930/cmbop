<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2937; }
        h1 { font-size: 18px; color: #0b6266; margin: 0 0 6px; }
        .meta { color: #64748b; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #e2e8f0; padding: 6px 8px; text-align: left; }
        th { background: #e8f8f7; color: #0b6266; }
    </style>
</head>
<body>
    <h1>{{ $title }}</h1>
    <div class="meta">
        {{ $user->name }} · {{ $user->email }} · Generated {{ $generatedAt->format('M j, Y g:i A') }}
    </div>
    <table>
        <thead>
            <tr>
                @foreach(array_keys($rows[0] ?? ['No data' => '']) as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $value)
                        <td>
                            @if($value instanceof \DateTimeInterface)
                                {{ $value->format('Y-m-d H:i') }}
                            @else
                                {{ $value }}
                            @endif
                        </td>
                    @endforeach
                </tr>
            @empty
                <tr><td>No data available for this report.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
