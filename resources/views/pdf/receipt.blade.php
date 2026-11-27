@php
    use App\Models\Payment;
    use App\Support\Format;
    $invoice = $receipt->invoice;
    $visit = $invoice->visit;
    $branch = $receipt->branch;

    // Logo dari Pengaturan → Aplikasi (fallback logo bawaan), disematkan base64 agar aman untuk Dompdf.
    $logoData = null;
    if (\App\Support\Settings::bool('receipt_show_logo')) {
        $logoFile = $branch->clinic->logo_path ? storage_path('app/public/'.$branch->clinic->logo_path) : public_path('images/logo.png');
        if (is_file($logoFile)) {
            $logoData = 'data:'.(mime_content_type($logoFile) ?: 'image/png').';base64,'.base64_encode(file_get_contents($logoFile));
        }
    }
    $contact = collect([
        $branch->phone ?? $branch->clinic->phone,
        ($wa = $branch->whatsapp ?: \App\Support\Settings::get('clinic_whatsapp')) ? 'No. WA '.$wa : null,
        \App\Support\Settings::get('clinic_email'),
    ])->filter()->implode(' · ');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Kwitansi {{ $receipt->code }}</title>
    <style>
        @page { margin: 14mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10.5px; color: #26352b; }
        h1 { font-size: 16px; margin: 0; color: #34583c; }
        .muted { color: #6d776b; }
        table { width: 100%; border-collapse: collapse; }
        .items th { text-align: left; font-size: 9px; color: #6d776b; text-transform: uppercase; border-bottom: 1px solid #e2e7dd; padding: 5px 0; }
        .items td { padding: 5px 0; border-bottom: 1px solid #f1f3ef; }
        .r { text-align: right; }
        .total td { font-weight: bold; font-size: 12px; padding-top: 6px; }
        .box { background: #eaf1da; padding: 8px 10px; margin-top: 10px; font-style: italic; }
        .void { color: #9b3b4e; font-size: 20px; font-weight: bold; text-align: center; border: 2px solid #9b3b4e; padding: 6px; }
    </style>
</head>
<body>
    <table>
        <tr>
            @if ($logoData)
                <td style="width: 52px; vertical-align: top"><img src="{{ $logoData }}" style="width: 44px; height: 44px"></td>
            @endif
            <td>
                <h1>{{ $branch->clinic->name }}</h1>
                @if ($tagline = \App\Support\Settings::get('clinic_tagline'))<div class="muted">{{ $tagline }}</div>@endif
                <div class="muted">{{ $branch->name }} · {{ $branch->address }}</div>
                <div class="muted">{{ $contact }}</div>
            </td>
            <td class="r" style="vertical-align: top">
                <b style="font-size: 13px">KWITANSI</b><br>
                <span class="muted">No.</span> {{ $receipt->code }}<br>
                <span class="muted">{{ $invoice->paid_at?->translatedFormat('j F Y H.i') }}</span>
            </td>
        </tr>
    </table>

    @if ($receipt->voided_at)
        <p class="void">DIBATALKAN — {{ $receipt->void_reason }}</p>
    @endif

    <table style="margin-top: 14px">
        @if ($visit)
            <tr><td class="muted" style="width: 90px">Pasien</td><td>{{ $visit->patient->name }} ({{ $visit->patient->rmCode() }})</td></tr>
            <tr><td class="muted">Dokter</td><td>{{ $visit->doctor->name }}</td></tr>
            <tr><td class="muted">Tanggal periksa</td><td>{{ $visit->visit_date->translatedFormat('j F Y') }}</td></tr>
        @else
            {{-- Penjualan langsung (tanpa pemeriksaan) --}}
            <tr><td class="muted" style="width: 90px">Pembeli</td><td>{{ $invoice->payerName() }}{{ $invoice->patient ? ' ('.$invoice->patient->rmCode().')' : '' }}</td></tr>
            <tr><td class="muted">Jenis</td><td>Penjualan barang</td></tr>
            <tr><td class="muted">Tanggal</td><td>{{ ($invoice->paid_at ?? $invoice->created_at)->translatedFormat('j F Y') }}</td></tr>
        @endif
    </table>

    <table class="items" style="margin-top: 12px">
        <thead><tr><th>{{ $visit ? 'Tindakan' : 'Barang' }}</th><th class="r">Jml</th><th class="r">Harga</th><th class="r">Jumlah</th></tr></thead>
        <tbody>
            @foreach ($invoice->items as $i)
                <tr>
                    <td>{{ $i->name }}@if ($i->tooth) <span class="muted">(gigi {{ $i->tooth }})</span>@endif</td>
                    <td class="r">{{ $i->quantity }}</td>
                    <td class="r">{{ Format::rupiah($i->unit_price) }}</td>
                    <td class="r">{{ Format::rupiah($i->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <table style="margin-top: 6px">
        <tr><td class="r muted">Subtotal</td><td class="r" style="width: 110px">{{ Format::rupiah($invoice->subtotal) }}</td></tr>
        @if ($invoice->discount)
            <tr><td class="r muted">Diskon ({{ $invoice->discount_reason }})</td><td class="r">−{{ Format::rupiah($invoice->discount) }}</td></tr>
        @endif
        <tr class="total"><td class="r">Total dibayar</td><td class="r">{{ Format::rupiah($invoice->total) }}</td></tr>
        <tr><td class="r muted">Metode</td><td class="r">{{ $invoice->payments->map(fn ($p) => Payment::METHODS[$p->method] ?? $p->method)->unique()->implode(', ') }}</td></tr>
    </table>

    <div class="box">Terbilang: {{ ucfirst(Format::terbilang($invoice->total)) }} rupiah</div>

    {{-- Tanda tangan: Dokter (bila ada pemeriksaan) dan Petugas; pesan penutup di bawahnya. --}}
    <table style="margin-top: 26px">
        <tr>
            <td style="width: 40%"></td>
            @if ($visit)
                <td style="width: 30%; text-align: center">Dokter,<br><br><br>{{ $visit->doctor->name }}</td>
            @endif
            <td class="r">Petugas,<br><br><br>{{ $invoice->payments->last()?->receiver?->name ?? '________________' }}</td>
        </tr>
    </table>
    @if ($branch->receipt_footer)
        <p class="muted" style="margin-top: 22px">{{ $branch->receipt_footer }}</p>
    @endif
</body>
</html>
