<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Revenue Report</title>

<style>
    body {
        font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
        font-size: 11px;
        color: #222;
        margin: 20px;
    }

    .header {
        text-align: center;
        margin-bottom: 20px;
    }

    .header h2 {
        margin: 0;
        font-size: 18px;
    }

    /* Summary Card */
    .summary-box {
        width: 100%;
        border: 1px solid #ccc;
        padding: 10px 12px;
        background: #f9f9f9;
        margin-bottom: 25px;
    }

    .summary-row {
        margin-bottom: 6px;
        font-size: 12px;
    }

    .summary-row span.label {
        font-weight: bold;
    }

    /* Main Table */
    table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 10px;
        margin-bottom: 20px;
    }

    th, td {
        border: 1px solid #ddd;
        padding: 6px 8px;
    }

    th {
        background: #f2f2f2;
        font-weight: bold;
    }

    td:nth-child(2) { text-align: center; }
    td:nth-child(3) { text-align: right; }

    tr { page-break-inside: avoid; }

</style>
</head>
<body>
<div>
<div class="header">
    <h2>Revenue Report</h2>
    <div>{{ $from ?? '' }} to {{ $to ?? '' }}</div>
</div>

<!-- SUMMARY FIXED (NO TABLES) -->
<div class="summary-box">
    <div class="summary-row">
        <span class="label">Filter Applied:</span>
        @if($owner_id)
            Owner: {{ $owners->where('id', $owner_id)->first()->owner_name ?? 'Unknown' }}
        @else
            All Owners
        @endif
    </div>

    <div class="summary-row">
        <span class="label">Total Revenue (All Partners):</span>
        ₹{{ number_format($totalRevenue ?? 0, 2) }}
    </div>

    <div class="summary-row">
        <span class="label">Your Revenue (Luxury Limoexpress):</span>
        ₹{{ number_format($ownRevenue ?? 0, 2) }}
    </div>
</div>

<h4 style="margin-bottom: 6px;">Revenue by Partner</h4>

<table>
    <thead>
        <tr>
            <th>Partner Name</th>
            <th>Number of Trips</th>
            <th>Revenue (₹)</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($partnerData as $partner)
            <tr>
                <td>{{ $partner['name'] }}</td>
                <td>{{ $partner['trip_count'] }}</td>
                <td>₹{{ number_format($partner['revenue'], 2) }}</td>
            </tr>
        @empty
            <tr>
                <td colspan="3" style="text-align:center;">No data available</td>
            </tr>
        @endforelse
    </tbody>
</table>
</div>
</body>
</html>
