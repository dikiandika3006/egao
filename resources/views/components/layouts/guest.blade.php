@props(['title' => null])
<!DOCTYPE html>
<html lang="id">
<head>
    @include('components.layouts.partials.head')
</head>
<body class="min-h-dvh font-sans">
<div class="grid min-h-dvh lg:grid-cols-[1.1fr_1fr]">
    {{-- Panel merek, mengikuti sampul desain --}}
    <section class="relative hidden flex-col justify-between bg-brand-600 p-12 text-white lg:flex">
        <div class="flex items-center justify-between">
            <p class="text-sm font-semibold tracking-wide text-leaf-200">EGAO&nbsp; / &nbsp;DENTAL CARE</p>
            <span class="rounded-full bg-sun px-5 py-2 text-[11px] font-bold text-brand-700">MVP</span>
        </div>

        <div>
            <h1 class="text-[44px] leading-[1.3] font-semibold tracking-tight">Klinik lebih rapi.<br>Waktu untuk pasien lebih banyak.</h1>
            <p class="mt-6 max-w-lg text-lg text-white/85">Input sekali, kwitansi langsung cetak. Satu data untuk Batu dan Malang.</p>
        </div>

        <div class="grid grid-cols-3 gap-4 text-sm">
            @foreach ([['01', 'Daftarkan pasien', 'Admin mencari nama, HP atau no. RM.'], ['02', 'Catat pemeriksaan', 'Odontogram, diagnosis ICD-10, tindakan.'], ['03', 'Bayar & cetak', 'Kasir menerima tindakan otomatis.']] as [$n, $t, $d])
                <div class="rounded-2xl bg-white/8 p-4">
                    <span class="rounded-md bg-leaf-100 px-2 py-1 text-xs font-bold text-brand-600">{{ $n }}</span>
                    <p class="mt-3 font-semibold">{{ $t }}</p>
                    <p class="mt-1 text-white/70">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </section>

    <section class="flex items-center justify-center px-5 py-12">
        <div class="w-full max-w-[400px]">
            <x-logo size="lg" class="mb-10" />
            {{ $slot }}
        </div>
    </section>
</div>
@include('components.layouts.partials.scripts')
</body>
</html>
