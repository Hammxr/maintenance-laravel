<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Change Log</title>
    <style>
        @page {
            margin: 28px 32px;
        }

        body {
            font-family: 'Helvetica', sans-serif;
            color: #111827;
            font-size: 10px;
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

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            color: #475569;
            border-bottom: 1px solid #cbd5e1;
            padding: 5px 6px;
        }

        td {
            border-bottom: 1px solid #e2e8f0;
            padding: 6px 6px;
            vertical-align: top;
        }

        .event-created {
            color: #15803d;
            font-weight: bold;
        }

        .event-deleted {
            color: #b91c1c;
            font-weight: bold;
        }

        .event-updated {
            color: #1d4ed8;
            font-weight: bold;
        }

        .change-list {
            margin: 0;
            padding-left: 12px;
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
    <h1>Change Log</h1>
    <div class="period">
        {{ $startDate->format('M j, Y') }} &mdash; {{ $endDate->format('M j, Y') }}
        &nbsp;|&nbsp; {{ count($entries) }} change{{ count($entries) === 1 ? '' : 's' }}
    </div>

    <table>
        <thead>
            <tr>
                <th>Date</th>
                <th>Changed By</th>
                <th>Type</th>
                <th>Record</th>
                <th>Event</th>
                <th>Changes</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($entries as $entry)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($entry['date'])->format('M j, Y g:ia') }}</td>
                    <td>{{ $entry['causer_name'] }}</td>
                    <td>{{ $entry['subject_type'] }}</td>
                    <td>{{ $entry['subject_name'] }}</td>
                    <td class="event-{{ strtolower($entry['event']) }}">{{ $entry['event'] }}</td>
                    <td>
                        @if (count($entry['changes']) > 0)
                            <ul class="change-list">
                                @foreach ($entry['changes'] as $change)
                                    <li>{{ $change }}</li>
                                @endforeach
                            </ul>
                        @else
                            &mdash;
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">No changes were logged for this period.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="footer">
        {{ config('app.name') }} &mdash; Change Log Report
    </div>
</body>
</html>
