<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Unplanned Maintenance</title>
    <style>
        @page {
            margin: 28px 32px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            color: #111827;
            font-size: 11px;
        }

        h1 {
            font-size: 20px;
            margin-bottom: 2px;
            color: #0f172a;
        }

        .period {
            color: #475569;
            margin-bottom: 18px;
            font-size: 11px;
        }

        .maintenance-block {
            padding: 8px 10px;
            margin-bottom: 8px;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #ea580c;
        }

        .maintenance-block-title {
            font-weight: bold;
            font-size: 11.5px;
            color: #0f172a;
            margin-bottom: 3px;
        }

        .maintenance-block-description {
            margin-bottom: 5px;
        }

        .maintenance-block-meta {
            font-size: 9.5px;
            color: #475569;
        }

        .footer {
            margin-top: 24px;
            font-size: 9px;
            color: #94a3b8;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>Unplanned Maintenance</h1>
    <div class="period">
        {{ $startDate->format('M j, Y') }} &mdash; {{ $endDate->format('M j, Y') }}
        &nbsp;|&nbsp; {{ count($unplanned) }} item{{ count($unplanned) === 1 ? '' : 's' }}
    </div>

    @forelse ($unplanned as $entry)
        <div class="maintenance-block">
            <div class="maintenance-block-title">{{ $entry['title'] }}</div>

            @if (filled($entry['description']))
                <div class="maintenance-block-description">{{ $entry['description'] }}</div>
            @endif

            <div class="maintenance-block-meta">
                Performed on <strong>{{ $entry['performed_at'] ? \Carbon\Carbon::parse($entry['performed_at'])->format('M j, Y') : 'N/A' }}</strong>
                by <strong>{{ $entry['technician_name'] }}</strong>
                @if (filled($entry['equipment_name']))
                    &nbsp;|&nbsp; Equipment: <strong>{{ $entry['equipment_name'] }}</strong>
                @endif
            </div>
        </div>
    @empty
        <p>No unplanned maintenance was logged in this period.</p>
    @endforelse

    <div class="footer">
        {{ config('app.name') }} &mdash; Unplanned Maintenance Report
    </div>
</body>
</html>
