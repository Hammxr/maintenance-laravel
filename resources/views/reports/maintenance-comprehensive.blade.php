<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Maintenance Report</title>
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

        h2 {
            font-size: 14px;
            margin-top: 22px;
            margin-bottom: 8px;
            padding-bottom: 4px;
            border-bottom: 2px solid #0f766e;
            color: #0f172a;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        th {
            text-align: left;
            background-color: #f1f5f9;
            padding: 6px 8px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            border-bottom: 1px solid #cbd5e1;
        }

        td {
            padding: 6px 8px;
            border-bottom: 1px solid #e2e8f0;
            font-size: 10.5px;
        }

        .summary-grid {
            width: 100%;
        }

        .summary-cell {
            width: 25%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            text-align: center;
        }

        .summary-label {
            font-size: 9px;
            text-transform: uppercase;
            color: #64748b;
            letter-spacing: 0.03em;
        }

        .summary-value {
            font-size: 18px;
            font-weight: bold;
            color: #0f172a;
            margin-top: 4px;
        }

        .badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: bold;
        }

        .badge-green {
            background-color: #dcfce7;
            color: #15803d;
        }

        .badge-yellow {
            background-color: #fef9c3;
            color: #a16207;
        }

        .badge-red {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .maintenance-block {
            padding: 8px 10px;
            margin-bottom: 8px;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #0f766e;
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

        .insight {
            padding: 8px 10px;
            margin-bottom: 8px;
            border: 1px solid #cbd5e1;
            border-left: 4px solid #64748b;
        }

        .insight-critical {
            border-left-color: #dc2626;
            background-color: #fef2f2;
        }

        .insight-warning {
            border-left-color: #ea580c;
            background-color: #fffbeb;
        }

        .insight-info {
            border-left-color: #0284c7;
            background-color: #f0f9ff;
        }

        .insight-category {
            font-weight: bold;
            font-size: 11px;
        }

        .insight-message {
            margin-top: 3px;
        }

        .insight-recommendation {
            margin-top: 4px;
            font-style: italic;
            color: #334155;
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
    <h1>Maintenance Report</h1>
    <div class="period">
        Period: {{ \Carbon\Carbon::parse($report['period']['start_date'])->format('M j, Y') }}
        &ndash;
        {{ \Carbon\Carbon::parse($report['period']['end_date'])->format('M j, Y') }}
        ({{ $report['period']['days'] }} days)
        &nbsp;|&nbsp; Generated {{ now()->format('M j, Y g:ia') }}
    </div>

    <h2>Summary</h2>
    <table class="summary-grid">
        <tr>
            <td class="summary-cell">
                <div class="summary-label">Mean Time To Repair</div>
                <div class="summary-value">{{ number_format($report['mttr'], 2) }} hrs</div>
            </td>
            <td class="summary-cell">
                <div class="summary-label">Total Cost</div>
                <div class="summary-value">R{{ number_format($report['cost_analysis']['total_cost'], 2) }}</div>
            </td>
            <td class="summary-cell">
                <div class="summary-label">Parts Cost</div>
                <div class="summary-value">R{{ number_format($report['cost_analysis']['parts_cost'], 2) }}</div>
            </td>
            <td class="summary-cell">
                <div class="summary-label">Labor Cost</div>
                <div class="summary-value">R{{ number_format($report['cost_analysis']['labor_cost'], 2) }}</div>
            </td>
        </tr>
    </table>
    <p>Total work orders completed in period: <strong>{{ $report['cost_analysis']['total_work_orders'] }}</strong></p>

    <h2>Maintenance Performed</h2>
    @forelse ($report['maintenance_log'] as $entry)
        <div class="maintenance-block">
            <div class="maintenance-block-title">{{ $entry['title'] }}</div>

            @if (filled($entry['description']))
                <div class="maintenance-block-description">{{ $entry['description'] }}</div>
            @endif

            <div class="maintenance-block-meta">
                Performed on
                <strong>{{ $entry['performed_at'] ? \Carbon\Carbon::parse($entry['performed_at'])->format('M j, Y') : 'N/A' }}</strong>
                by
                <strong>{{ $entry['technician_name'] }}</strong>
                @if (filled($entry['equipment_name']))
                    &nbsp;|&nbsp; Equipment: <strong>{{ $entry['equipment_name'] }}</strong>
                @endif
            </div>
        </div>
    @empty
        <p>No maintenance was completed in this period.</p>
    @endforelse

    <h2>Technician Performance</h2>
    <table>
        <thead>
            <tr>
                <th>Technician</th>
                <th>Assigned</th>
                <th>Completed</th>
                <th>In Progress</th>
                <th>Completion Rate</th>
                <th>Avg Time (hrs)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['technician_performance'] as $tech)
                <tr>
                    <td>{{ $tech['technician_name'] }}</td>
                    <td>{{ $tech['total_assigned'] }}</td>
                    <td>{{ $tech['completed'] }}</td>
                    <td>{{ $tech['in_progress'] }}</td>
                    <td>
                        @php
                            $rateClass = $tech['completion_rate'] >= 80 ? 'badge-green' : ($tech['completion_rate'] >= 60 ? 'badge-yellow' : 'badge-red');
                        @endphp
                        <span class="badge {{ $rateClass }}">{{ number_format($tech['completion_rate'], 2) }}%</span>
                    </td>
                    <td>{{ number_format($tech['average_completion_time_hours'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6">No technician activity in this period.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>Actionable Insights</h2>
    @forelse ($report['actionable_insights'] as $insight)
        @php
            $insightClass = match($insight['type']) {
                'critical' => 'insight-critical',
                'warning' => 'insight-warning',
                default => 'insight-info',
            };
        @endphp
        <div class="insight {{ $insightClass }}">
            <div class="insight-category">{{ $insight['category'] }}</div>
            <div class="insight-message">{{ $insight['message'] }}</div>
            <div class="insight-recommendation">Recommendation: {{ $insight['recommendation'] }}</div>
        </div>
    @empty
        <div class="insight insight-info">
            <div class="insight-message">No critical issues detected. All metrics are within acceptable ranges.</div>
        </div>
    @endforelse

    <div class="footer">
        {{ config('app.name') }} &mdash; Maintenance Report &mdash; Page 1
    </div>
</body>
</html>
