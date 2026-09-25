<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Spend summary</title>
    <style>
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 11px; color: #0f172a; }
        h1 { color: #1a585e; font-size: 18px; margin: 0 0 8px; }
        .muted { color: #75787B; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 6px 4px; text-align: left; }
        th { font-size: 10px; text-transform: uppercase; color: #75787B; }
        .num { text-align: right; }
        .kpi { width: 100%; margin: 12px 0 18px; }
        .kpi td { border: 0; padding: 8px 10px; background: #e6f5f5; }
    </style>
</head>
<body>
    <h1>Marketplace spend summary</h1>
    <div class="muted">{{ $user->name }} · {{ $user->email }}</div>
    <div class="muted">
        Period:
        {{ !empty($range['from']) ? $range['from'] : 'lifetime' }}
        –
        {{ !empty($range['to']) ? $range['to'] : 'now' }}
    </div>

    <table class="kpi">
        <tr>
            <td><strong>Net</strong><br>{{ format_money($summary['net']) }}</td>
            <td><strong>Gross</strong><br>{{ format_money($summary['gross']) }}</td>
            <td><strong>Refunded</strong><br>{{ format_money($summary['refunded']) }}</td>
            <td><strong>In progress</strong><br>{{ format_money($summary['in_progress']) }}</td>
        </tr>
    </table>

    <h3 style="color:#1a585e;margin:0 0 6px;">By payment method</h3>
    <table>
        <thead>
            <tr><th>Method</th><th class="num">Net</th><th class="num">Orders</th></tr>
        </thead>
        <tbody>
            @forelse($methods as $m)
                <tr>
                    <td>{{ $m['label'] }}</td>
                    <td class="num">{{ format_money($m['net']) }}</td>
                    <td class="num">{{ $m['orders'] }}</td>
                </tr>
            @empty
                <tr><td colspan="3" class="muted">No rows</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3 style="color:#1a585e;margin:18px 0 6px;">
        Orders
        @if(!empty($truncated))
            (showing {{ count($rows) }} of {{ (int) $rowTotal }}; export CSV for the full list)
        @else
            ({{ count($rows) }})
        @endif
    </h3>
    @if(!empty($truncated))
        <p class="muted" style="margin:0 0 8px;">
            This PDF lists the first {{ (int) $rowLimit }} orders. KPIs above still cover the full period.
            Use CSV export for a complete order-level file.
        </p>
    @endif
    <table>
        <thead>
            <tr>
                <th>Date</th><th>Order</th><th>Site</th>
                <th class="num">Net</th><th>Status</th><th>Invoice</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td>{{ $row['order_number'] }}</td>
                    <td>{{ $row['site'] }}</td>
                    <td class="num">{{ format_money($row['net']) }}</td>
                    <td>{{ $row['order_status'] }}</td>
                    <td>{{ $row['invoice_number'] }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No orders in this period</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
