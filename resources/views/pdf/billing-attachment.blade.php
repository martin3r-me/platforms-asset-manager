<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #111827; margin: 0; }
        h1 { font-size: 17px; margin: 0 0 2px 0; }
        .sub { font-size: 11px; color: #6b7280; margin: 0 0 14px 0; }
        .muted { color: #6b7280; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }
        .row { width: 100%; }
        .row td { vertical-align: top; padding: 2px 0; }
        .label { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .total { font-size: 15px; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { text-align: left; font-size: 9px; text-transform: uppercase; letter-spacing: .04em;
            color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 5px 6px; }
        table.items td { padding: 4px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        table.items tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
        .num { text-align: right; color: #9ca3af; width: 28px; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #4b5563; }
        .footer { margin-top: 18px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    <h1>User-Pauschale</h1>
    <p class="sub">{{ $profileName }} · {{ $periodLabel }}</p>

    <div class="box">
        <table class="row">
            <tr>
                <td style="width:34%;">
                    <div class="label">Empfänger</div>
                    <div><strong>{{ $customerName ?: '—' }}</strong></div>
                </td>
                <td style="width:33%;">
                    <div class="label">Abgerechnete Plätze</div>
                    <div class="total">{{ number_format($quantity, 0, ',', '.') }}</div>
                    <div class="muted">à {{ number_format($unitPrice, 2, ',', '.') }} € netto</div>
                </td>
                <td style="width:33%;">
                    <div class="label">Betrag netto</div>
                    <div class="total">{{ number_format($totalNet, 2, ',', '.') }} €</div>
                    <div class="muted">{{ $basisLabel }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="num">#</th>
                <th>Name</th>
                <th>Benutzerkonto</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rows as $row)
                @php
                    // Der Name führt. Fehlt er ganz (Träger seither gelöscht), tritt das Benutzerkonto
                    // an seine Stelle — eine leere Zelle wäre auf einem Nachweis die schlechtere Auskunft.
                    $displayName = filled($row['name'] ?? null) && $row['name'] !== $row['upn']
                        ? $row['name']
                        : $row['upn'];
                @endphp
                <tr>
                    <td class="num">{{ $loop->iteration }}</td>
                    <td>{{ $displayName }}</td>
                    <td class="mono">{{ $row['upn'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="footer">
        Stand {{ $countedAt }}. Aufgeführt sind die zum Zeitpunkt der Rechnungsstellung
        abgerechneten Plätze; spätere Ein- und Austritte wirken sich erst auf den Folgemonat aus.
        @if($skippedCount > 0)
            {{ $skippedCount }} weitere Asset-Träger wurden nicht abgerechnet.
        @endif
        @if($documentId)
            · Zu Beleg #{{ $documentId }}.
        @endif
    </p>
</body>
</html>
