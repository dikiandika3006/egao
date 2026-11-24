<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        <div class="flex gap-2">
            <button type="button" wire:click="export" class="btn-soft">Ekspor Excel</button>
            <a href="{{ $pdfUrl }}" target="_blank" class="btn-ghost">PDF</a>
        </div>
    </x-page-header>

    <div class="mt-7 flex flex-wrap items-end gap-3">
        <div>
            <label class="label text-xs">Dari</label>
            <input type="date" wire:model.live="from" class="input min-h-10">
        </div>
        <div>
            <label class="label text-xs">Sampai</label>
            <input type="date" wire:model.live="to" class="input min-h-10">
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach (['hari' => 'Hari ini', 'minggu' => '7 hari', 'bulan' => 'Bulan ini', 'bulan-lalu' => 'Bulan lalu', 'tahun' => 'Tahun ini'] as $k => $l)
                <button type="button" wire:click="preset('{{ $k }}')" class="btn-ghost min-h-10 px-3 text-xs">{{ $l }}</button>
            @endforeach
        </div>
        <p class="ml-auto text-xs text-muted">{{ $context->label() }} · {{ $r['from']->translatedFormat('j M Y') }} – {{ $r['to']->translatedFormat('j M Y') }}</p>
    </div>

    <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card bg-leaf-100 p-4 lg:p-5">
            <p class="text-[13px] text-muted">Pemasukan</p>
            <p class="mt-3 text-[20px] font-semibold tracking-tight lg:text-[26px]">@rupiah($r['in'])</p>
        </div>
        <div class="card p-4 lg:p-5">
            <p class="text-[13px] text-muted">Pengeluaran</p>
            <p class="mt-3 text-[20px] font-semibold tracking-tight lg:text-[26px]">@rupiah($r['out'])</p>
        </div>
        <div @class(['rounded-2xl p-4 lg:p-5', 'bg-brand-600 text-white' => $r['net'] >= 0, 'bg-rose-soft text-rose-ink' => $r['net'] < 0])>
            <p class="text-[13px] opacity-85">Selisih kas</p>
            <p class="mt-3 text-[20px] font-semibold tracking-tight lg:text-[26px]">{{ $r['net'] < 0 ? '−' : '' }}@rupiah(abs($r['net']))</p>
        </div>
        <div class="card p-4 lg:p-5">
            <p class="text-[13px] text-muted">Kunjungan selesai</p>
            <p class="mt-3 text-[20px] font-semibold tracking-tight lg:text-[26px]">{{ $r['visits'] }}</p>
            @if ($r['receivable'] > 0)<p class="mt-1 text-[12px] text-muted">Piutang saat ini <b class="text-amber-ink">@rupiah($r['receivable'])</b> · lihat Kasir › Piutang</p>@endif
        </div>
    </div>

    <div class="mt-6 flex gap-10 border-b border-line text-sm">
        @foreach (['ringkasan' => 'Ringkasan', 'pembayaran' => 'Pembayaran ('.$r['payments']->count().')', 'pengeluaran' => 'Pengeluaran'] as $k => $label)
            <button type="button" wire:click="setTab('{{ $k }}')" @class([
                '-mb-px border-b-2 pb-3 transition',
                'border-leaf-500 font-semibold text-brand-600' => $tab === $k,
                'border-transparent text-muted hover:text-brand-700' => $tab !== $k,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'ringkasan')
    @if ($r['perBranch']->count() > 1)
        <section class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[520px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr><th class="py-3.5 pl-5">Cabang</th><th class="text-right">Pemasukan</th><th class="text-right">Pengeluaran</th><th class="pr-5 text-right">Selisih</th></tr>
                </thead>
                <tbody>
                    @foreach ($r['perBranch'] as $row)
                        <tr class="border-b border-line last:border-0">
                            <td class="py-3 pl-5 font-medium">{{ $row['branch']->shortName() }}</td>
                            <td class="text-right">@rupiah($row['in'])</td>
                            <td class="text-right">@rupiah($row['out'])</td>
                            <td class="pr-5 text-right font-semibold">@rupiah($row['net'])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </section>
    @endif

    <div class="mt-6 grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="card overflow-hidden">
            <h2 class="px-5 pt-5 text-lg font-semibold">Harian</h2>
            <div class="mt-3 max-h-[560px] overflow-y-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-canvas text-[10px] font-medium text-muted uppercase">
                        <tr><th class="py-3 pl-5">Tanggal</th><th class="hidden sm:table-cell"></th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="pr-5 text-right">Selisih</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($r['daily'] as $d)
                            <tr class="border-b border-line last:border-0">
                                <td class="py-2.5 pl-5 text-[13px]">{{ $d['date']->translatedFormat('D, j M') }}</td>
                                <td class="hidden w-[30%] sm:table-cell">
                                    <div class="h-1.5 rounded-full bg-leaf-500" style="width: {{ round($d['in'] / $maxDaily * 100) }}%"></div>
                                    <div class="mt-0.5 h-1.5 rounded-full bg-rose-ink/50" style="width: {{ round($d['out'] / $maxDaily * 100) }}%"></div>
                                </td>
                                <td class="text-right text-[13px]">@rupiah($d['in'])</td>
                                <td class="text-right text-[13px]">@rupiah($d['out'])</td>
                                <td @class(['pr-5 text-right text-[13px] font-semibold', 'text-rose-ink' => $d['net'] < 0])>@rupiah($d['net'])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <div class="space-y-6">
            <section class="card p-5">
                <h2 class="font-semibold">Metode pembayaran</h2>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($r['methods'] as $m)
                        <li class="flex justify-between py-2.5"><span>{{ $m['label'] }} <span class="text-xs text-muted">({{ $m['count'] }})</span></span><b>@rupiah($m['total'])</b></li>
                    @empty
                        <li class="py-2 text-muted">—</li>
                    @endforelse
                </ul>
            </section>
            <section class="card p-5">
                <h2 class="font-semibold">Tindakan terbanyak</h2>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($r['procedures'] as $p)
                        <li class="flex justify-between gap-2 py-2.5"><span>{{ $p->name }} <span class="text-xs text-muted">×{{ $p->qty }}</span></span><b>@rupiah($p->total)</b></li>
                    @empty
                        <li class="py-2 text-muted">—</li>
                    @endforelse
                </ul>
            </section>
            <section class="card p-5">
                <h2 class="font-semibold">Pengeluaran per kategori</h2>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($r['categories'] as $c)
                        <li><button type="button" wire:click="filterCategory({{ $c['id'] }})" class="flex w-full justify-between py-2.5 text-left hover:text-brand-600"><span>{{ $c['name'] }}</span><b>@rupiah($c['total'])</b></button></li>
                    @empty
                        <li class="py-2 text-muted">—</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>

    @elseif ($tab === 'pembayaran')
        {{-- Laporan pembayaran: rincian per transaksi --}}
        <div class="mt-6 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
            @forelse ($r['methods'] as $m)
                <div class="card p-4">
                    <p class="text-[12px] text-muted">{{ $m['label'] }} · {{ $m['count'] }}×</p>
                    <p class="mt-2 font-semibold">@rupiah($m['total'])</p>
                </div>
            @empty
                <p class="text-sm text-muted">Belum ada pembayaran di periode ini.</p>
            @endforelse
        </div>

        <section class="card mt-4 overflow-x-auto">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3.5 pl-5">Waktu</th>
                        @if ($r['branches']->count() > 1)<th>Cabang</th>@endif
                        <th>Pasien</th><th>Dokter</th><th>Kwitansi</th><th>Metode</th><th>Kasir</th>
                        <th class="pr-5 text-right">Jumlah</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($r['payments']->reverse() as $p)
                        <tr class="border-b border-line last:border-0">
                            <td class="py-2.5 pl-5 text-[13px] whitespace-nowrap">{{ $p['paid_at']->translatedFormat('j M, H.i') }}</td>
                            @if ($r['branches']->count() > 1)<td class="text-[13px]">{{ $r['branches']->get($p['branch_id'])?->shortName() }}</td>@endif
                            <td><p class="text-[13px] font-medium">{{ $p['buyer'] }}</p><p class="text-[11px] text-muted">{{ $p['patient']?->rmCode() }}</p></td>
                            <td class="text-[13px]">{{ $p['doctor'] ?? '—' }}</td>
                            <td class="text-[12px] text-muted">{{ $p['receipt'] ?? '—' }}</td>
                            <td class="text-[13px]">{{ $p['method_label'] }}</td>
                            <td class="text-[12px] text-muted">{{ $p['cashier'] ?? '—' }}</td>
                            <td class="pr-5 text-right font-semibold">@rupiah($p['amount'])</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-12 text-center text-muted">Belum ada pembayaran di periode ini.</td></tr>
                    @endforelse
                </tbody>
                @if ($r['payments']->isNotEmpty())
                    <tfoot><tr class="bg-canvas/70"><td colspan="{{ $r['branches']->count() > 1 ? 7 : 6 }}" class="py-3 pl-5 font-semibold">Total pemasukan</td><td class="pr-5 text-right font-semibold">@rupiah($r['in'])</td></tr></tfoot>
                @endif
            </table>
        </section>

    @else
        {{-- Laporan pengeluaran per kategori: listrik & air, lab gigi, bahan, gaji, dll --}}
        <div class="mt-6 grid min-w-0 gap-6 lg:grid-cols-[320px_minmax(0,1fr)]">
            <section class="card p-5">
                <h2 class="font-semibold">Per kategori</h2>
                <ul class="mt-3 space-y-1 text-sm">
                    <li>
                        <button type="button" wire:click="filterCategory(null)" @class(['flex w-full justify-between rounded-lg px-3 py-2.5 text-left', 'bg-leaf-100 font-semibold text-brand-600' => ! $category, 'hover:bg-mist' => $category])>
                            <span>Semua kategori</span><span>@rupiah($r['out'])</span>
                        </button>
                    </li>
                    @foreach ($r['categories'] as $c)
                        <li>
                            <button type="button" wire:click="filterCategory({{ $c['id'] }})" @class(['w-full rounded-lg px-3 py-2.5 text-left', 'bg-leaf-100 text-brand-600' => $category === $c['id'], 'hover:bg-mist' => $category !== $c['id']])>
                                <span class="flex justify-between"><span @class(['font-semibold' => $category === $c['id']])>{{ $c['name'] }}</span><span class="font-semibold">@rupiah($c['total'])</span></span>
                                <span class="mt-1.5 block h-1.5 rounded-full bg-mist"><span class="block h-1.5 rounded-full bg-leaf-500" style="width: {{ $c['share'] }}%"></span></span>
                                @if ($r['branches']->count() > 1)
                                    <span class="mt-1 block text-[11px] text-muted">
                                        @foreach ($r['branches'] as $bid => $b){{ $b->shortName() }} @rupiah($c['branches'][$bid] ?? 0)@if (! $loop->last) · @endif @endforeach
                                    </span>
                                @endif
                            </button>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-4 text-[11px] text-muted">Kategori tanpa transaksi di periode ini tidak ditampilkan. Kategori baru ditambah di Pengaturan.</p>
            </section>

            <section class="card overflow-x-auto">
                <div class="flex items-center justify-between px-5 pt-5">
                    <h2 class="font-semibold">{{ $r['category']?->name ?? 'Semua pengeluaran' }}</h2>
                    <span class="text-sm font-semibold">@rupiah($r['expenses']->sum('amount'))</span>
                </div>
                <table class="mt-3 w-full min-w-[560px] text-left text-sm">
                    <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                        <tr><th class="py-3 pl-5">Tanggal</th>@if ($r['branches']->count() > 1)<th>Cabang</th>@endif<th>Kategori</th><th>Keterangan</th><th class="pr-5 text-right">Jumlah</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($r['expenses']->sortByDesc('date') as $e)
                            <tr class="border-b border-line last:border-0">
                                <td class="py-2.5 pl-5 text-[13px] whitespace-nowrap">{{ $e->date->translatedFormat('j M Y') }}</td>
                                @if ($r['branches']->count() > 1)<td class="text-[13px]">{{ $r['branches']->get($e->branch_id)?->shortName() }}</td>@endif
                                <td class="text-[13px]">{{ $e->category->name }}</td>
                                <td class="text-[13px] text-muted">{{ $e->description ?: '—' }}</td>
                                <td class="pr-5 text-right font-semibold">@rupiah($e->amount)</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-12 text-center text-muted">Tidak ada pengeluaran di periode ini.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </section>
        </div>
    @endif
</div>
