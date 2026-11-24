@props(['name', 'active' => false, 'tile' => true, 'compact' => false])
@php
    // Ikon garis (gaya Lucide) per menu + warna lembut khas tiap modul.
    $icons = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'antrean' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'pasien' => '<path d="M16 3h3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><circle cx="12" cy="10" r="3"/><path d="M7 18a5 5 0 0 1 10 0"/><path d="M9 3h6"/>',
        'rekam-medis' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11v6"/><path d="M9 14h6"/>',
        'kasir' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/><path d="M6 15h4"/>',
        'jadwal' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01"/>',
        'dokter' => '<path d="M4.8 2.3A.3.3 0 1 0 5 2H4a2 2 0 0 0-2 2v5a6 6 0 0 0 6 6 6 6 0 0 0 6-6V4a2 2 0 0 0-2-2h-1a.2.2 0 1 0 .3.3"/><path d="M8 15v1a6 6 0 0 0 6 6 6 6 0 0 0 6-6v-4"/><circle cx="20" cy="10" r="2"/>',
        'janji' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="m9 16 2 2 4-4"/>',
        'stok' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'lab' => '<path d="M10 2v7.31"/><path d="M14 9.3V2"/><path d="M8.5 2h7"/><path d="M14 9.3a6.5 6.5 0 1 1-4 0"/><path d="M5.52 16h12.96"/>',
        'laporan' => '<path d="M3 3v18h18"/><path d="M8 17v-5"/><path d="M13 17V8"/><path d="M18 17v-9"/>',
        'pengeluaran' => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M8 8h8"/><path d="M8 12h8"/><path d="M8 16h5"/>',
        'gaji' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
        'pengaturan' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'master-barang' => '<path d="M3 7h18"/><path d="M5 7v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7"/><path d="M8 3h8l2 4H6Z"/><path d="M10 12h4"/>',
        'tindakan' => '<path d="M12 5.5c-1.5-2-4.5-2.5-6-1C4 6 4.5 10 5.5 13c.7 2 1 4.5 1.5 6.5.3 1.2 1.8 1.3 2.2.1L10.5 15c.3-1 2.7-1 3 0l1.3 4.6c.4 1.2 1.9 1.1 2.2-.1.5-2 .8-4.5 1.5-6.5 1-3 1.5-7-.5-8.5-1.5-1.5-4.5-1-6 1Z"/>',
        'kategori-pengeluaran' => '<path d="M12.6 2.6A2 2 0 0 0 11.2 2H4a2 2 0 0 0-2 2v7.2a2 2 0 0 0 .6 1.4l8.7 8.7a2.4 2.4 0 0 0 3.4 0l6.6-6.6a2.4 2.4 0 0 0 0-3.4Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'tarif-gaji' => '<line x1="19" x2="5" y1="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'absensi' => '<path d="M20 10c0 4.99-5.54 10.19-7.4 11.8a1 1 0 0 1-1.2 0C9.54 20.19 4 14.99 4 10a8 8 0 0 1 16 0"/><path d="m9 10 2 2 4-4"/>',
        'tentang' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'keluar' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
        'lainnya' => '<circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/>',
    ];

    $tones = [
        'dashboard' => 'bg-leaf-100 text-brand-600',
        'antrean' => 'bg-sky-soft text-sky-ink',
        'pasien' => 'bg-indigo-soft text-indigo-ink',
        'rekam-medis' => 'bg-rose-soft text-rose-ink',
        'kasir' => 'bg-amber-soft text-amber-ink',
        'jadwal' => 'bg-orange-soft text-orange-ink',
        'dokter' => 'bg-leaf-200 text-brand-600',
        'janji' => 'bg-indigo-soft text-indigo-ink',
        'stok' => 'bg-orange-soft text-orange-ink',
        'lab' => 'bg-leaf-100 text-brand-600',
        'laporan' => 'bg-sky-soft text-sky-ink',
        'pengeluaran' => 'bg-rose-soft text-rose-ink',
        'gaji' => 'bg-amber-soft text-amber-ink',
        'pengaturan' => 'bg-mist text-brand-500',
        'master-barang' => 'bg-amber-soft text-amber-ink',
        'tindakan' => 'bg-sky-soft text-sky-ink',
        'kategori-pengeluaran' => 'bg-rose-soft text-rose-ink',
        'tarif-gaji' => 'bg-amber-soft text-amber-ink',
        'absensi' => 'bg-leaf-200 text-brand-600',
        'tentang' => 'bg-leaf-50 text-brand-600',
        'keluar' => 'bg-rose-soft text-rose-ink',
        'lainnya' => 'bg-mist text-brand-500',
    ];
@endphp
<span {{ $attributes->class([
    'inline-flex shrink-0 items-center justify-center transition',
    'size-8 rounded-lg' => $tile && ! $compact,
    'size-7 rounded-lg' => $tile && $compact,
    'bg-brand-600 text-white' => $tile && $active,
    $tones[$name] ?? 'bg-mist text-brand-500' => $tile && ! $active,
]) }} aria-hidden="true">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" @class(['size-[18px]' => ! $compact, 'size-4' => $compact])>{!! $icons[$name] ?? $icons['lainnya'] !!}</svg>
</span>
