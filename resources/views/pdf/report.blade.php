@php
    use App\Support\Format;
    $multi = $r['branches']->count() > 1;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan {{ $label }}</title>
    <style>
        @page { margin: 16mm 14mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #26352b; line-height: 1.4; }
        .head { border-bottom: 2px solid #34583c; padding-bottom: 6px; }
        .clinic { font-size: 16px; font-weight: bold; color: #34583c; }
        .muted { color: #6d776b; }
        h2 { font-size: 10px; text-transform: uppercase; color: #34583c; margin: 16px 0 5px; border-bottom: 1px solid #e2e7dd; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 3px 5px; text-align: left; vertical-align: top; }
        th { font-size: 7.5px; text-transform: uppercase; color: #6d776b; background: #f6f8f2; }
        .r { text-align: right; }
        .rows td { border-bottom: 1px solid #f1f3ef; }
        .kpi td { width: 25%; padding: 8px; background: #f6f8f2; border: 3px solid #fff; }
        .kpi .v { font-size: 13px; font-weight: bold; }
        tfoot td { font-weight: bold; border-top: 1.5px solid #34583c; }
        .footer { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5px; color: #6d776b; }
    </style>
</head>
<body>
<div class="footer">{{ $clinic->name }} · {{ $label }} · Dicetak {{ now()->translatedFormat('j F Y H.i') }} oleh {{ auth()->user()->name }}</div>

<div class="head">
    <table><tr>
        <td><div class="clinic">{{ $clinic->name }}</div><div class="muted">Laporan keuangan · {{ $label }}</div></td>
        <td class="r"><strong>{{ $r['from']->translatedFormat('j M Y') }} – {{ $r['to']->translatedFormat('j M Y') }}</strong></td>
    </tr></table>
</div>

<table class="kpi" style="margin-top:10px">
    <tr>
        <td><div class="muted">Pemasukan</div><div class="v">{{ Format::rupiah($r['in']) }}</div></td>
        <td><div class="muted">Pengeluaran</div><div class="v">{{ Format::rupiah($r['out']) }}</div></td>
        <td><div class="muted">Selisih kas</div><div class="v">{{ $r['net'] < 0 ? '−' : '' }}{{ Format::rupiah(abs($r['net'])) }}</div></td>
        <td><div class="muted">Kunjungan selesai</div><div class="v">{{ $r['visits'] }}</div></td>
    </tr>
</table>

@if ($multi)
    <h2>Per cabang</h2>
    <table class="rows">
        <tr><th>Cabang</th><th class="r">Pemasukan</th><th class="r">Pengeluaran</th><th class="r">Selisih</th></tr>
        @foreach ($r['perBranch'] as $b)
            <tr><td>{{ $b['branch']->name }}</td><td class="r">{{ Format::rupiah($b['in']) }}</td><td class="r">{{ Format::rupiah($b['out']) }}</td><td class="r">{{ Format::rupiah($b['net']) }}</td></tr>
        @endforeach
    </table>
@endif

<table style="margin-top:4px">
    <tr>
        <td style="width:50%; padding-left:0">
            <h2>Pembayaran per metode</h2>
            <table class="rows">
                @forelse ($r['methods'] as $m)
                    <tr><td>{{ $m['label'] }} ({{ $m['count'] }}×)</td><td class="r">{{ Format::rupiah($m['total']) }}</td></tr>
                @empty
                    <tr><td class="muted">—</td></tr>
                @endforelse
            </table>
        </td>
        <td style="width:50%; padding-right:0">
            <h2>Pengeluaran per kategori</h2>
            <table class="rows">
                @forelse ($r['categories'] as $c)
                    <tr><td>{{ $c['name'] }}</td><td class="r">{{ Format::rupiah($c['total']) }}</td></tr>
                @empty
                    <tr><td class="muted">—</td></tr>
                @endforelse
            </table>
        </td>
    </tr>
</table>

<h2>Tindakan terbanyak</h2>
<table class="rows">
    <tr><th>Tindakan</th><th class="r">Jumlah</th><th class="r">Nilai</th></tr>
    @forelse ($r['procedures'] as $p)
        <tr><td>{{ $p->name }}</td><td class="r">{{ $p->qty }}</td><td class="r">{{ Format::rupiah($p->total) }}</td></tr>
    @empty
        <tr><td class="muted" colspan="3">—</td></tr>
    @endforelse
</table>

<h2>Rincian pembayaran</h2>
<table class="rows">
    <tr><th>Waktu</th>@if ($multi)<th>Cabang</th>@endif<th>Pasien</th><th>Kwitansi</th><th>Metode</th><th class="r">Jumlah</th></tr>
    @forelse ($r['payments'] as $p)
        <tr>
            <td>{{ $p['paid_at']->format('d/m H.i') }}</td>
            @if ($multi)<td>{{ $r['branches']->get($p['branch_id'])?->shortName() }}</td>@endif
            <td>{{ $p['buyer'] }} <span class="muted">{{ $p['patient']?->rmCode() }}</span></td>
            <td class="muted">{{ $p['receipt'] ?? '—' }}</td>
            <td>{{ $p['method_label'] }}</td>
            <td class="r">{{ Format::rupiah($p['amount']) }}</td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Belum ada pembayaran.</td></tr>
    @endforelse
    <tfoot><tr><td colspan="{{ $multi ? 5 : 4 }}">Total pemasukan</td><td class="r">{{ Format::rupiah($r['in']) }}</td></tr></tfoot>
</table>

<h2>Rincian pengeluaran{{ $r['category'] ? ' — '.$r['category']->name : '' }}</h2>
<table class="rows">
    <tr><th>Tanggal</th>@if ($multi)<th>Cabang</th>@endif<th>Kategori</th><th>Keterangan</th><th class="r">Jumlah</th></tr>
    @forelse ($r['expenses'] as $e)
        <tr>
            <td>{{ $e->date->format('d/m/Y') }}</td>
            @if ($multi)<td>{{ $r['branches']->get($e->branch_id)?->shortName() }}</td>@endif
            <td>{{ $e->category->name }}</td>
            <td class="muted">{{ $e->description ?: '—' }}</td>
            <td class="r">{{ Format::rupiah($e->amount) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">Tidak ada pengeluaran.</td></tr>
    @endforelse
    <tfoot><tr><td colspan="{{ $multi ? 4 : 3 }}">Total</td><td class="r">{{ Format::rupiah($r['expenses']->sum('amount')) }}</td></tr></tfoot>
</table>

<p class="muted" style="margin-top:10px">Angka bersifat sementara bila masih ada tagihan belum lunas (FR-LAP-10). Neraca & laba rugi lengkap di luar lingkup versi ini.</p>
</body>
</html>
