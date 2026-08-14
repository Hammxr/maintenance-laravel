<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Overdue Maintenance</title>
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
            border-left: 4px solid #dc2626;
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

        .maintenance-block-overdue {
            font-size: 9.5px;
            color: #b91c1c;
            font-weight: bold;
            margin-top: 3px;
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
    <h1>Overdue Maintenance</h1>
    <div class="period">
        As of {{ now()->format('M j, Y g:ia') }}
        &nbsp;|&nbsp; {{ count($overdue) }} item{{ count($overdue) === 1 ? '' : 's' }} overdue
    </div>

    @forelse ($overdue as $entry)
        <div class="maintenance-block">
            <div class="maintenance-block-title">{{ $entry['title'] }}</div>

            @if (filled($entry['description']))
                <div class="maintenance-block-description">{{ $entry['description'] }}</div>
            @endif

            <div class="maintenance-block-meta">
                Assigned to <strong>{{ $entry['technician_name'] }}</strong>
                @if (filled($entry['equipment_name']))
                    &nbsp;|&nbsp; Equipment: <strong>{{ $entry['equipment_name'] }}</strong>
                @endif
            </div>

            <div class="maintenance-block-overdue">{{ $entry['overdue_summary'] }}</div>
        </div>
    @empty
        <p>Nothing is overdue right now — all maintenance is up to date.</p>
    @endforelse

    <div class="footer">
        {{ config('app.name') }} &mdash; Overdue Maintenance Report
    </div>
</body>
</html>
