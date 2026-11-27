<div class="w-full">
    <h1 class="text-[26px] font-semibold tracking-tight lg:text-[32px]">{{ $item['title'] }}</h1>
    @if ($item['subtitle'])
        <p class="mt-2 max-w-2xl text-sm text-muted lg:text-[15px]">{{ $item['subtitle'] }}</p>
    @endif

    <div class="card mt-8 flex flex-col items-start gap-4 p-6 lg:p-8">
        <span class="pill bg-amber-soft text-amber-ink">Dalam pengembangan</span>
        <p class="text-lg font-semibold">Modul {{ $item['label'] }} sedang disiapkan.</p>
        <p class="max-w-xl text-sm text-muted">
            Struktur menu, hak akses peran, dan tabel databasenya sudah tersedia.
            Layar ini akan mengikuti desain final dan kebutuhan {{ $item['requirements'] }} pada SRS v2.
        </p>
        <a href="{{ route('dashboard') }}" wire:navigate class="btn-soft mt-2">Kembali ke dashboard</a>
    </div>
</div>
