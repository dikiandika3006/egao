@php
    $fmtSize = fn ($b) => $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.').' MB' : number_format(max($b, 0) / 1024, 0, ',', '.').' KB';
@endphp

<section class="card mt-6 p-5 lg:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="flex items-center gap-2 text-lg font-semibold">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" class="size-5 text-brand-600" aria-hidden="true"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
                Backup &amp; restore database
            </h2>
            <p class="mt-1 text-sm text-muted">Khusus Owner. Dilindungi PIN.</p>
        </div>
        @if ($unlocked)
            <div class="flex items-center gap-2 text-xs text-muted">
                Terbuka sampai {{ $until->format('H.i') }}
                <button type="button" wire:click="lock" class="btn-ghost min-h-9 px-3 text-xs">Kunci</button>
            </div>
        @endif
    </div>

    @if (! $enabled)
        <p class="mt-4 rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
            Fitur belum aktif. Isi <code class="font-semibold">BACKUP_PIN</code> di file <code>.env</code> server, lalu muat ulang halaman.
        </p>
    @elseif (! $unlocked)
        {{-- Masukkan PIN --}}
        <form wire:submit="unlock" class="mt-4 flex flex-wrap items-start gap-2">
            <div class="w-full max-w-[220px]">
                <label for="backup-pin" class="sr-only">PIN backup</label>
                <input wire:model="pin" id="backup-pin" type="password" inputmode="numeric" autocomplete="off" class="input tracking-[0.3em]" placeholder="PIN">
                <x-error for="pin" />
            </div>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled" wire:target="unlock">Buka panel</button>
        </form>
    @else
        {{-- Buat backup --}}
        <div class="mt-5 rounded-xl border border-line p-4">
            <p class="font-semibold">Backup sekarang</p>
            <p class="mt-1 text-sm text-muted">Ukuran database ±{{ $fmtSize($databaseSize) }}. File dikompresi (.sql.gz), disimpan di server, dan langsung diunduh. Backup otomatis juga berjalan tiap hari jam {{ config('backup.daily_at') }}.</p>
            <button type="button" wire:click="createBackup" wire:loading.attr="disabled" wire:target="createBackup" class="btn-primary mt-3">
                <span wire:loading.remove wire:target="createBackup">Buat backup &amp; unduh</span>
                <span wire:loading wire:target="createBackup">Membuat backup…</span>
            </button>
            <x-error for="backup" />
        </div>

        {{-- Riwayat --}}
        <div class="mt-5">
            <p class="font-semibold">Riwayat backup di server</p>
            @if (count($backups))
                <ul class="mt-2 divide-y divide-line overflow-hidden rounded-xl border border-line">
                    @foreach ($backups as $b)
                        <li wire:key="bk-{{ $b['name'] }}" @class(['flex flex-wrap items-center gap-3 px-4 py-2.5 text-sm', 'bg-leaf-50' => $restoreName === $b['name']])>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">
                                    {{ \Carbon\Carbon::createFromTimestamp($b['time'])->translatedFormat('j M Y, H.i') }}
                                    @if (str_contains($b['name'], '_pre-restore'))<span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink">sebelum restore</span>@endif
                                </p>
                                <p class="truncate text-[11px] text-muted">{{ $b['name'] }} · {{ $fmtSize($b['size']) }}</p>
                            </div>
                            <div class="flex gap-1.5">
                                <button type="button" wire:click="download('{{ $b['name'] }}')" class="btn-ghost min-h-9 px-3 text-xs">Unduh</button>
                                <button type="button" wire:click="pickRestore('{{ $b['name'] }}')" class="btn-soft min-h-9 px-3 text-xs">Pilih untuk restore</button>
                                <button type="button" wire:click="delete('{{ $b['name'] }}')" wire:confirm="Hapus file backup {{ $b['name'] }}?" class="btn-ghost min-h-9 px-3 text-xs text-rose-ink">Hapus</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="mt-2 text-sm text-muted">Belum ada file backup.</p>
            @endif
        </div>

        {{-- Restore --}}
        <div class="mt-5 rounded-xl border border-rose-ink/40 bg-rose-soft/40 p-4">
            <p class="font-semibold text-rose-ink">Restore database</p>
            <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-brand-700">
                <li>Seluruh database akan <strong>ditimpa</strong> dengan isi file backup.</li>
                <li>Data yang dibuat setelah tanggal backup akan <strong>hilang</strong>.</li>
                <li>Selama proses aplikasi masuk mode perawatan; setelah selesai semua pengguna <strong>login ulang</strong>.</li>
                <li>Sebelum restore, sistem otomatis membuat backup "sebelum restore" untuk berjaga-jaga.</li>
            </ul>

            <div class="mt-4 space-y-3">
                <div>
                    <p class="label">Sumber</p>
                    @if ($restoreName)
                        <p class="text-sm">Dari riwayat: <span class="font-semibold">{{ $restoreName }}</span>
                            <button type="button" wire:click="$set('restoreName', null)" class="ml-2 text-xs text-brand-600 hover:underline">Ganti</button></p>
                    @else
                        <input wire:model="restoreFile" type="file" accept=".sql,.gz" class="input py-2">
                        <p class="mt-1 text-[11px] text-muted">Pilih "Pilih untuk restore" di riwayat, atau upload file .sql / .sql.gz.</p>
                        <div wire:loading wire:target="restoreFile" class="mt-1 text-xs text-muted">Mengunggah…</div>
                        <x-error for="restoreFile" />
                    @endif
                </div>
                <div>
                    <label for="backup-confirm" class="label">Ketik <strong>RESTORE</strong> untuk konfirmasi</label>
                    <input wire:model="confirm" id="backup-confirm" type="text" autocomplete="off" class="input max-w-[220px]" placeholder="RESTORE">
                    <x-error for="confirm" />
                </div>
                <x-error for="restore" />
                <button type="button" wire:click="restore" wire:loading.attr="disabled" wire:target="restore,restoreFile" class="btn w-full bg-rose-ink text-white hover:bg-rose-ink/90">
                    <span wire:loading.remove wire:target="restore">Restore database</span>
                    <span wire:loading wire:target="restore">Memulihkan… jangan tutup halaman</span>
                </button>
            </div>
        </div>
    @endif
</section>
