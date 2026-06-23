<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 12px; color: #111827; margin: 0; }
        h1 { font-size: 18px; margin: 0 0 2px 0; }
        .muted { color: #6b7280; }
        .sub { font-size: 11px; color: #6b7280; margin: 0 0 16px 0; }
        .box { border: 1px solid #e5e7eb; border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; }
        .row { width: 100%; }
        .row td { vertical-align: top; padding: 2px 0; }
        .label { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        table.items { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.items th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: .04em;
            color: #6b7280; border-bottom: 1px solid #e5e7eb; padding: 6px 6px; }
        table.items td { padding: 6px 6px; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
        .pill { font-size: 10px; padding: 1px 6px; border-radius: 10px; }
        .pill-open { background: #ecfdf5; color: #047857; }
        .pill-ret { background: #f3f4f6; color: #4b5563; }
        .sig-img { border: 1px solid #e5e7eb; border-radius: 6px; max-height: 120px; }
        .footer { margin-top: 24px; font-size: 10px; color: #9ca3af; }
    </style>
</head>
<body>
    @php
        $emp = $handover->employee;
        $recipientName = $emp?->display_name ?: ($emp?->user_principal_name ?? '—');
    @endphp

    <h1>Geräteausgabe – Übergabeprotokoll</h1>
    <p class="sub">Protokoll-Nr. {{ $handover->id }} · Ausgegeben am
        {{ $handover->issued_at?->format('d.m.Y') ?? '—' }}</p>

    <div class="box">
        <table class="row">
            <tr>
                <td style="width:50%;">
                    <div class="label">Empfänger</div>
                    <div><strong>{{ $recipientName }}</strong></div>
                    @if($emp?->user_principal_name)<div class="muted">{{ $emp->user_principal_name }}</div>@endif
                    @if($emp?->department)<div class="muted">{{ $emp->department }}</div>@endif
                </td>
                <td style="width:50%;">
                    <div class="label">Status</div>
                    <div>{{ $handover->statusLabel() }}</div>
                </td>
            </tr>
        </table>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th>Gerät</th>
                <th>Seriennummer</th>
                <th>Modell</th>
                <th>Zubehör</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($handover->lines as $line)
                @php
                    $snap = is_array($line->device_snapshot) ? $line->device_snapshot : [];
                    $model = $snap['model'] ?? $line->device?->model;
                    $acc   = is_array($line->accessories) ? implode(', ', $line->accessories) : '';
                @endphp
                <tr>
                    <td>{{ $line->deviceName() }}</td>
                    <td>{{ $line->serialNumber() ?: '—' }}</td>
                    <td>{{ $model ?: '—' }}</td>
                    <td>{{ $acc !== '' ? $acc : '—' }}</td>
                    <td>
                        @if($line->returned_at)
                            <span class="pill pill-ret">zurück {{ $line->returned_at->format('d.m.Y') }}</span>
                            @if($line->return_condition)<div class="muted">{{ $line->return_condition }}</div>@endif
                        @else
                            <span class="pill pill-open">ausgegeben</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Keine Geräte erfasst.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if($handover->notes)
        <div class="box">
            <div class="label">Notiz</div>
            <div>{{ $handover->notes }}</div>
        </div>
    @endif

    <div class="box">
        <div class="label">Empfangsbestätigung</div>
        <p style="margin:4px 0 8px 0;">
            Hiermit bestätige ich den Empfang der oben aufgeführten Geräte in ordnungsgemäßem Zustand.
        </p>
        @if($handover->isSigned())
            <img class="sig-img" src="{{ $handover->signature_data }}" alt="Unterschrift">
            <div style="margin-top:6px;">
                <strong>{{ $handover->signer_name ?: $recipientName }}</strong>
                @if($handover->signed_at)<span class="muted"> · {{ $handover->signed_at->format('d.m.Y H:i') }}</span>@endif
            </div>
        @else
            <div style="height:60px; border-bottom:1px solid #9ca3af; width:60%;"></div>
            <div class="muted" style="margin-top:4px;">Unterschrift (noch nicht erfasst)</div>
        @endif
    </div>

    <div class="footer">Erstellt am {{ now()->format('d.m.Y H:i') }} · Asset Manager</div>
</body>
</html>
