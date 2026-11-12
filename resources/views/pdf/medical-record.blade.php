@php use App\Support\Format; $patient = $visit->patient; $clinic = $patient->clinic; @endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekam medis {{ $patient->rmCode() }}</title>
    <style>
        @page { margin: 28mm 18mm 22mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #26352b; line-height: 1.5; }
        .kop { position: fixed; top: -20mm; left: 0; right: 0; border-bottom: 2px solid #34583c; padding-bottom: 6px; }
        .kop .name { font-size: 18px; font-weight: bold; color: #34583c; }
        .kop .sub { font-size: 9.5px; color: #6d776b; }
        h1 { font-size: 15px; margin: 0 0 2px; }
        h2 { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #34583c; margin: 16px 0 6px; border-bottom: 1px solid #e2e7dd; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 4px 6px; vertical-align: top; text-align: left; }
        .grid td { border-bottom: 1px solid #eef1ea; }
        .muted { color: #6d776b; }
        .allergy { background: #fff4cc; color: #7b6217; padding: 6px 8px; margin-top: 8px; }
        .right { text-align: right; }
        .ttd { margin-top: 36px; width: 220px; float: right; text-align: center; }
        .footer { position: fixed; bottom: -14mm; left: 0; right: 0; font-size: 8.5px; color: #6d776b; }
    </style>
</head>
<body>
<div class="kop">
    <div class="name">{{ $clinic->name }}</div>
    <div class="sub">{{ $visit->branch->name }} — {{ $visit->branch->address }}{{ $visit->branch->phone ? ' • '.$visit->branch->phone : '' }}</div>
</div>
<div class="footer">
    Dokumen rahasia rekam medis. Dicetak {{ now()->translatedFormat('j F Y H.i') }} oleh {{ auth()->user()->name }} • Versi {{ $record->version ?: 'draf' }}
</div>

<h1>Ringkasan Rekam Medis</h1>
<div class="muted">Kunjungan {{ $visit->visit_date->translatedFormat('l, j F Y') }} • {{ $visit->doctor->name }}</div>

<table style="margin-top:10px">
    <tr>
        <td style="width:50%"><strong>{{ $patient->name }}</strong><br>{{ $patient->rmCode() }} • {{ $patient->genderLabel() }} • {{ $patient->age() }} tahun</td>
        <td>Tanggal lahir: {{ $patient->birth_date->translatedFormat('j F Y') }}<br>NIK: {{ $patient->maskedNik() ?? '—' }}</td>
    </tr>
</table>
<div class="allergy">Alergi: {{ $patient->hasAllergy() ? $patient->allergies : 'tidak ada / tidak dilaporkan' }}{{ $record->allergy_confirmed ? ' (dikonfirmasi)' : '' }}</div>

<h2>Anamnesis</h2>
<table>
    <tr><td style="width:28%" class="muted">Keluhan utama</td><td>{{ $record->complaint ?: '—' }}</td></tr>
    @if ($record->anamnesis)<tr><td class="muted">Anamnesis</td><td>{{ $record->anamnesis }}</td></tr>@endif
    @if ($record->blood_pressure)<tr><td class="muted">Tekanan darah</td><td>{{ $record->blood_pressure }}</td></tr>@endif
    @if ($record->current_medication)<tr><td class="muted">Obat dikonsumsi</td><td>{{ $record->current_medication }}</td></tr>@endif
</table>

<h2>Pemeriksaan gigi (FDI)</h2>
@if ($visit->findings->isEmpty())
    <div class="muted">Tidak ada temuan yang dicatat.</div>
@else
    <table class="grid">
        <tr class="muted"><th style="width:14%">Gigi</th><th style="width:26%">Kondisi</th><th>Permukaan / catatan</th></tr>
        @foreach ($visit->findings->sortBy('tooth') as $f)
            <tr><td>{{ $f->tooth }}</td><td>{{ $f->condition->name }}</td><td>{{ $f->surfaceLabel() }}{{ $f->note ? ' — '.$f->note : '' }}</td></tr>
        @endforeach
    </table>
@endif

<h2>Diagnosis (ICD-10)</h2>
@forelse ($visit->diagnoses as $d)
    <div>{{ $d->icd10_code }} — {{ $d->icd10->name_en }}{{ $d->icd10->name_id ? ' ('.$d->icd10->name_id.')' : '' }}{{ $d->is_primary ? ' • utama' : '' }}</div>
@empty
    <div class="muted">—</div>
@endforelse

<h2>Rencana perawatan</h2>
<div>{{ $record->plan ?: '—' }}</div>
@if ($record->follow_up_label)
    <div class="muted">{{ $record->follow_up_label }}{{ $record->follow_up_date ? ' — sekitar '.$record->follow_up_date->translatedFormat('j F Y') : '' }}</div>
@endif

<h2>Tindakan</h2>
@if ($visit->invoice?->items->isNotEmpty())
    <table class="grid">
        @foreach ($visit->invoice->items as $item)
            <tr><td>{{ $item->name }}{{ $item->tooth ? ' (gigi '.$item->tooth.')' : '' }}</td><td class="right" style="width:12%">{{ $item->quantity }}×</td><td class="right" style="width:22%">{{ Format::rupiah($item->amount) }}</td></tr>
        @endforeach
        <tr><td><strong>Total</strong></td><td></td><td class="right"><strong>{{ Format::rupiah($visit->invoice->total) }}</strong></td></tr>
    </table>
@else
    <div class="muted">—</div>
@endif

<h2>Resep</h2>
@forelse ($visit->prescriptions as $rx)
    <div>R/ {{ $rx->drug_name }}{{ $rx->dose ? ' — '.$rx->dose : '' }}{{ $rx->quantity ? ' — '.$rx->quantity : '' }}</div>
@empty
    <div class="muted">Tidak ada obat diresepkan.</div>
@endforelse

<div class="ttd">
    {{ $visit->branch->city }}, {{ $visit->visit_date->translatedFormat('j F Y') }}<br>Dokter pemeriksa
    <div style="height:56px"></div>
    <strong>{{ $visit->doctor->name }}</strong><br>
    <span class="muted">{{ $visit->doctor->sip_number }}</span>
</div>
</body>
</html>
