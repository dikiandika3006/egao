@php
    use App\Support\Format;
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
    $isDoctor = $type === 'doctor';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Slip gaji {{ $payee->name }} {{ $month->translatedFormat('F Y') }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9.5px; color: #26352b; line-height: 1.45; }
        .head { border-bottom: 2px solid #34583c; padding-bottom: 6px; margin-bottom: 10px; }
        .clinic { font-size: 15px; font-weight: bold; color: #34583c; }
        .muted { color: #6d776b; }
        h1 { font-size: 13px; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 4px; text-align: left; vertical-align: top; }
        th { font-size: 8px; text-transform: uppercase; color: #6d776b; border-bottom: 1px solid #e2e7dd; }
        .r { text-align: right; }
        .lines td { border-bottom: 1px solid #f1f3ef; }
        .sum td { padding: 4px; }
        .total td { font-size: 12px; font-weight: bold; color: #34583c; border-top: 2px solid #34583c; padding-top: 6px; }
        .sec { margin-top: 12px; font-size: 9px; font-weight: bold; text-transform: uppercase; color: #34583c; }
        .sign { margin-top: 26px; }
        .sign td { width: 50%; text-align: center; }
    </style>
</head>
<body>
<div class="head">
    <table>
        <tr>
            <td><div class="clinic">{{ $clinic->name }}</div><div class="muted">Slip gaji — rahasia</div></td>
            <td class="r"><h1>{{ $month->translatedFormat('F Y') }}</h1><div class="muted">Dicetak {{ now()->translatedFormat('j M Y H.i') }}</div></td>
        </tr>
    </table>
</div>

<table>
    <tr>
        <td style="width:55%"><strong style="font-size:11px">{{ $payee->name }}</strong><br>
            <span class="muted">{{ $isDoctor ? 'Dokter gigi'.($payee->sip_number ? ' • '.$payee->sip_number : '') : $payee->position }}</span></td>
        <td class="r muted">Cabang utama: {{ $branches->get($payee->primary_branch_id)?->name ?? '—' }}</td>
    </tr>
</table>

@if ($isDoctor)
    <div class="sec">Rincian tindakan lunas (lintas cabang)</div>
    @if ($row['lines']->isEmpty())
        <p class="muted">Belum ada tindakan lunas pada periode ini.</p>
    @else
        <table class="lines">
            <tr><th>Tanggal</th><th>Cabang</th><th>Tindakan</th><th class="r">Kotor</th><th class="r">Biaya modal</th><th class="r">Tarif</th><th class="r">Fee</th></tr>
            @foreach ($row['lines'] as $l)
                <tr>
                    <td>{{ $l['paid_at']?->format('d/m') }}</td>
                    <td>{{ $branches->get($l['branch_id'])?->shortName() }}</td>
                    <td>{{ $l['name'] }}{{ $l['quantity'] > 1 ? ' ×'.$l['quantity'] : '' }}</td>
                    <td class="r">{{ Format::rupiah($l['gross']) }}</td>
                    <td class="r">{{ $l['cost'] ? '−'.Format::rupiah($l['cost']) : '—' }}</td>
                    <td class="r">{{ $pct($l['percent']) }}%</td>
                    <td class="r">{{ Format::rupiah($l['share']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
@endif

<div class="sec">Ringkasan</div>
<table class="sum">
    @if ($isDoctor)
        <tr><td>Tindakan kotor ({{ $row['count'] }} tindakan)</td><td class="r">{{ Format::rupiah($row['gross']) }}</td></tr>
        @if ($row['lab'] > 0)
            <tr><td class="muted">Potongan order lab (biaya lab {{ Format::rupiah($row['lab']) }} × fee)</td><td class="r muted">−{{ Format::rupiah($row['lab_deduction']) }}</td></tr>
        @endif
        <tr><td>Fee tindakan (setelah biaya modal & lab)</td><td class="r">{{ Format::rupiah($row['share']) }}</td></tr>
    @endif
    <tr><td>Gaji pokok</td><td class="r">{{ Format::rupiah($row['base']) }}</td></tr>
    @if ($row['ud'] > 0)
        <tr><td>Uang duduk ({{ $pct($row['hours']) }} jam)</td><td class="r">{{ Format::rupiah($row['ud']) }}</td></tr>
    @endif
    @if (! $isDoctor && $row['role'] === 'asisten')
        <tr><td>Fee asisten ({{ $pct($row['assistant_percent']) }}% × {{ Format::rupiah($row['assisted']) }})</td><td class="r">{{ Format::rupiah($row['assistant_share']) }}</td></tr>
    @endif
    @if (! $isDoctor && $row['overtime'] > 0)
        <tr><td>Lembur ({{ $pct($row['overtime_hours']) }} jam)</td><td class="r">{{ Format::rupiah($row['overtime']) }}</td></tr>
    @endif
    @forelse ($row['adjustments'] as $a)
        <tr><td>{{ $a->typeLabel() }}{{ $a->note ? ' — '.$a->note : '' }}</td><td class="r">{{ $a->type === 'potongan' ? '−' : '' }}{{ Format::rupiah($a->amount) }}</td></tr>
    @empty
        <tr><td class="muted">Tanpa bonus & potongan</td><td></td></tr>
    @endforelse
    <tr class="total"><td>Total diterima</td><td class="r">{{ Format::rupiah($row['total']) }}</td></tr>
    <tr><td colspan="2" class="muted"><em>Terbilang: {{ ucfirst(Format::terbilang($row['total'])) }} rupiah</em></td></tr>
</table>

<table class="sign">
    <tr>
        <td>Diterima oleh<br><br><br><br><strong>{{ $payee->name }}</strong></td>
        <td>Disetujui<br><br><br><br><strong>Owner {{ $clinic->name }}</strong></td>
    </tr>
</table>
</body>
</html>
