@php use App\Models\Item; use App\Models\StockMovement; @endphp

<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($tab === 'barang')
            @if ($canEdit)<button type="button" wire:click="newItem" class="btn-primary">+ Barang baru</button>@endif
        @elseif ($tab === 'laporan')
            <button type="button" wire:click="export" class="btn-soft">Ekspor Excel</button>
        @endif
    </x-page-header>

    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <div class="card p-4"><p class="text-[13px] text-muted">Jenis barang aktif</p><p class="mt-2 text-xl font-semibold">{{ $allItems->count() }}</p></div>
        <div @class(['card p-4', 'bg-amber-soft' => $lowCount > 0])><p class="text-[13px] text-muted">Stok menipis</p><p @class(['mt-2 text-xl font-semibold', 'text-amber-ink' => $lowCount > 0])>{{ $lowCount }}</p></div>
        <div class="card col-span-2 p-4 lg:col-span-1"><p class="text-[13px] text-muted">Nilai persediaan · {{ $context->label() }}</p><p class="mt-2 text-xl font-semibold">@rupiah($stockValue)</p></div>
    </div>

    <div class="mt-6 flex gap-6 overflow-x-auto border-b border-line text-sm whitespace-nowrap sm:gap-10">
        @foreach ($tabs as $k => $label)
            <button type="button" wire:click="setTab('{{ $k }}')" @class([
                '-mb-px border-b-2 pb-3 transition',
                'border-leaf-500 font-semibold text-brand-600' => $tab === $k,
                'border-transparent text-muted hover:text-brand-700' => $tab !== $k,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'barang')
        <div class="mt-5 flex flex-wrap items-center gap-3">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau kode…" class="input max-w-xs">
            <div class="flex rounded-lg bg-mist p-1 text-xs">
                @foreach (['' => 'Semua'] + Item::CATEGORIES as $k => $label)
                    <button type="button" wire:click="$set('category', '{{ $k }}')" @class(['min-h-8 rounded-md px-3', 'bg-white font-semibold shadow-sm' => $category === $k, 'text-muted' => $category !== $k])>{{ $label }}</button>
                @endforeach
            </div>
        </div>

        <section class="card mt-4 overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3.5 pl-5">Barang</th>
                        @foreach ($branches as $b)<th class="text-right">Stok {{ $b->shortName() }}</th>@endforeach
                        <th class="text-right">Min.</th><th class="text-right">Harga beli</th><th class="text-right">Harga jual</th><th class="pr-5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $i)
                        <tr wire:key="i{{ $i->id }}" @class(['border-b border-line last:border-0', 'opacity-50' => ! $i->is_active])>
                            <td class="py-3 pl-5">
                                <p class="font-medium">{{ $i->name }}</p>
                                <p class="text-[11px] text-muted">{{ $i->code }} · {{ Item::CATEGORIES[$i->category] ?? $i->category }} · per {{ $i->unit }}</p>
                            </td>
                            @foreach ($branches as $bid => $b)
                                @php $q = $i->stockAt($bid); @endphp
                                <td @class(['text-right font-semibold', 'text-rose-ink' => $q <= 0, 'text-amber-ink' => $q > 0 && $i->min_stock > 0 && $q <= $i->min_stock])>{{ $q }}</td>
                            @endforeach
                            <td class="text-right text-muted">{{ $i->min_stock }}</td>
                            <td class="text-right">@rupiah($i->buy_price)</td>
                            <td class="text-right">{!! $i->sell_price !== null ? e(\App\Support\Format::rupiah($i->sell_price)) : '<span class="text-xs text-muted">tidak dijual</span>' !!}</td>
                            <td class="pr-5 text-right">@if ($canEdit)<button type="button" wire:click="editItem({{ $i->id }})" class="link-arrow">Ubah</button>@endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 6 + $branches->count() }}" class="py-12 text-center text-muted">Belum ada barang. Tambahkan obat, bahan, atau alat.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-muted">Merah = habis, kuning = di bawah stok minimum. Barang "tidak dijual" (mis. bahan habis pakai) hanya bisa dipakai, tidak muncul di kasir.</p>

    @elseif ($tab === 'pembelian')
        <div class="mt-5 grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_400px]">
            <section class="card overflow-x-auto">
                <h2 class="px-5 pt-5 font-semibold">Riwayat pembelian</h2>
                <ul class="mt-3 divide-y divide-line">
                    @forelse ($purchases as $p)
                        <li wire:key="p{{ $p->id }}" @class(["px-5 py-3.5", "bg-leaf-50" => $editingPurchaseId === $p->id])>
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-medium">{{ $p->date->translatedFormat('j M Y') }} · {{ $p->supplier ?: 'Tanpa pemasok' }} @if ($p->reference)<span class="text-xs text-muted">· {{ $p->reference }}</span>@endif</p>
                                <b>@rupiah($p->total)</b>
                            </div>
                            <p class="mt-1 text-[12px] text-muted">
                                @if ($context->isAll()){{ $p->branch->shortName() }} · @endif
                                {{ $p->items->map(fn ($l) => $l->item->name.' ×'.$l->quantity)->implode(', ') }}
                            </p>
                            <div class="mt-2 flex gap-4 text-xs">
                                @if ($canEdit)<button type="button" wire:click="editPurchase({{ $p->id }})" class="font-semibold text-brand-600 hover:underline">Ubah</button>@endif
                                @if ($canEdit)<button type="button" wire:click="askCancelPurchase({{ $p->id }})" class="text-rose-ink hover:underline">Batalkan</button>@endif
                            </div>
                        </li>
                    @empty
                        <li class="px-5 py-12 text-center text-sm text-muted">Belum ada pembelian.</li>
                    @endforelse
                </ul>
            </section>

            <form wire:submit="savePurchase" class="card h-fit p-5">
                @if ($editingPurchaseId)
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="font-semibold">Ubah pembelian</h2>
                        <button type="button" wire:click="cancelPurchaseEdit" class="text-xs text-muted hover:underline">Batal ubah</button>
                    </div>
                    <p class="mt-1 rounded-lg bg-amber-soft px-3 py-2 text-xs text-amber-ink">Selisih jumlah dicatat sebagai koreksi stok dan pengeluarannya ikut diperbarui. Barang yang sudah terpakai/terjual tidak bisa dikurangi melebihi sisa stok.</p>
                @else
                    <h2 class="font-semibold">Catat pembelian</h2>
                    <p class="mt-1 text-xs text-muted">Stok cabang bertambah dan total otomatis masuk ke Pengeluaran.</p>
                @endif
                <x-branch-pick :context="$context" label="Cabang penerima" class="mt-4" />
                <div class="mt-4 grid grid-cols-2 gap-2">
                    <div><label class="label text-xs">Tanggal</label><input type="date" wire:model="purchase.date" max="{{ today()->toDateString() }}" class="input"></div>
                    <div><label class="label text-xs">No. faktur</label><input type="text" wire:model="purchase.reference" maxlength="50" class="input"></div>
                    <div class="col-span-2"><label class="label text-xs">Pemasok</label><input type="text" wire:model="purchase.supplier" maxlength="255" class="input" placeholder="Mis. PT Dental Supply"></div>
                </div>
                <x-error for="purchase.date" />

                <div class="mt-4 space-y-2">
                    @foreach ($purchase['lines'] as $i => $line)
                        <div wire:key="l{{ $i }}" class="rounded-lg bg-canvas p-2.5">
                            <div class="flex gap-2">
                                <select wire:model.live="purchase.lines.{{ $i }}.item_id" class="input min-h-10 text-[13px]">
                                    <option value="">Pilih barang…</option>
                                    @foreach ($allItems as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} ({{ $opt->unit }})</option>@endforeach
                                </select>
                                <button type="button" wire:click="removeLine({{ $i }})" class="px-2 text-muted hover:text-rose-ink" title="Hapus">✕</button>
                            </div>
                            <div class="mt-2 grid grid-cols-[80px_1fr] gap-2">
                                <input type="number" wire:model.live.debounce.400ms="purchase.lines.{{ $i }}.quantity" min="1" class="input min-h-10 text-[13px]" aria-label="Jumlah">
                                <x-money model="purchase.lines.{{ $i }}.unit_cost" live placeholder="Harga beli / satuan" class="min-h-10 text-[13px]" />
                            </div>
                            <x-error for="purchase.lines.{{ $i }}.item_id" /><x-error for="purchase.lines.{{ $i }}.unit_cost" />
                        </div>
                    @endforeach
                </div>
                <button type="button" wire:click="addLine" class="mt-2 text-xs font-semibold text-brand-600 hover:underline">+ Tambah baris</button>
                <x-error for="lines" /><x-error for="stock" />

                <div class="mt-4 flex items-center justify-between border-t border-line pt-4">
                    <span class="text-sm text-muted">Total</span><b class="text-lg">@rupiah($purchaseTotal)</b>
                </div>
                <button type="submit" class="btn-primary mt-4 w-full" wire:loading.attr="disabled" wire:target="savePurchase">{{ $editingPurchaseId ? 'Simpan perubahan' : 'Simpan pembelian' }}</button>
            </form>
        </div>

        @if ($cancellingPurchaseId)
            <x-modal title="Batalkan pembelian" close="closeCancelPurchase">
                <form wire:submit="confirmCancelPurchase" class="space-y-4">
                    <p class="text-sm text-muted">Stok barang dari pembelian ini dikurangi kembali dan pengeluarannya dihapus. Riwayat mutasi stok tetap tersimpan.</p>
                    <div>
                        <label class="label">Alasan</label>
                        <input type="text" wire:model="cancelReason" class="input" placeholder="Mis. salah input, barang dikembalikan">
                        <x-error for="cancelReason" />
                    </div>
                    <x-error for="stock" />
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeCancelPurchase" class="btn-ghost">Tidak jadi</button>
                        <button class="btn-primary bg-rose-ink hover:bg-rose-ink/90">Batalkan pembelian</button>
                    </div>
                </form>
            </x-modal>
        @endif

    @elseif ($tab === 'pakai')
        <div class="mt-5 grid gap-6 lg:grid-cols-2">
            <form wire:submit="saveUsage" class="card p-5">
                <div class="flex rounded-lg bg-mist p-1 text-xs">
                    <button type="button" wire:click="$set('usage.mode', 'pakai')" @class(['min-h-9 flex-1 rounded-md', 'bg-white font-semibold shadow-sm' => $usage['mode'] === 'pakai', 'text-muted' => $usage['mode'] !== 'pakai'])>Pemakaian klinik</button>
                    <button type="button" wire:click="$set('usage.mode', 'opname')" @class(['min-h-9 flex-1 rounded-md', 'bg-white font-semibold shadow-sm' => $usage['mode'] === 'opname', 'text-muted' => $usage['mode'] !== 'opname'])>Stok opname</button>
                </div>
                <p class="mt-3 text-xs text-muted">
                    {{ $usage['mode'] === 'pakai' ? 'Catat bahan/alat yang terpakai untuk tindakan (mis. sarung tangan, bahan tambal).' : 'Hitung stok fisik di rak, lalu masukkan jumlahnya. Selisih dicatat sebagai penyesuaian.' }}
                </p>
                <div class="mt-4 space-y-3">
                    <x-branch-pick :context="$context" />
                    <div>
                        <label class="label">Barang</label>
                        <select wire:model.live="usage.item_id" class="input">
                            <option value="">Pilih barang…</option>
                            @foreach ($allItems as $opt)<option value="{{ $opt->id }}">{{ $opt->name }} ({{ $opt->unit }})</option>@endforeach
                        </select>
                        <x-error for="usage.item_id" />
                        @isset($currentStock)<p class="mt-1 text-xs text-muted">Stok sistem saat ini: <b class="text-brand-700">{{ $currentStock }}</b></p>@endisset
                    </div>
                    <div>
                        <label class="label">{{ $usage['mode'] === 'pakai' ? 'Jumlah dipakai' : 'Jumlah stok fisik' }}</label>
                        <input type="number" wire:model="usage.quantity" min="0" class="input">
                        <x-error for="usage.quantity" /><x-error for="stock" />
                    </div>
                    <div>
                        <label class="label">Keterangan {{ $usage['mode'] === 'opname' ? '' : '(opsional)' }}</label>
                        <input type="text" wire:model="usage.note" maxlength="255" class="input" placeholder="{{ $usage['mode'] === 'opname' ? 'Mis. opname akhir bulan, 2 kedaluwarsa' : 'Mis. untuk tindakan hari ini' }}">
                        <x-error for="usage.note" />
                    </div>
                </div>
                <button type="submit" class="btn-primary mt-5 w-full">Simpan</button>
            </form>
            <div class="rounded-2xl bg-leaf-100 p-5 text-sm text-brand-600">
                <h3 class="font-semibold">Alur stok di aplikasi</h3>
                <ul class="mt-3 list-disc space-y-1.5 pl-5">
                    <li><b>Pembelian</b> → stok bertambah, harga beli & HPP rata-rata diperbarui, pengeluaran tercatat.</li>
                    <li><b>Jual ke pasien</b> → dari resep dokter atau tombol "Tambah obat/barang" di Kasir; stok berkurang saat tagihan <b>lunas</b>.</li>
                    <li><b>Pemakaian</b> → bahan habis pakai untuk tindakan.</li>
                    <li><b>Opname</b> → samakan stok sistem dengan stok fisik.</li>
                </ul>
            </div>
        </div>

    @elseif ($tab === 'mutasi')
        <div class="mt-5 flex flex-wrap items-end gap-3">
            <div><label class="label text-xs">Dari</label><input type="date" wire:model.live="from" class="input min-h-10"></div>
            <div><label class="label text-xs">Sampai</label><input type="date" wire:model.live="to" class="input min-h-10"></div>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Filter nama barang…" class="input min-h-10 max-w-xs">
        </div>
        <section class="card mt-4 overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr><th class="py-3 pl-5">Waktu</th>@if ($branches->count() > 1)<th>Cabang</th>@endif<th>Barang</th><th>Jenis</th><th class="text-right">Jumlah</th><th>Keterangan</th><th class="pr-5">Oleh</th></tr>
                </thead>
                <tbody>
                    @forelse ($movements as $m)
                        <tr class="border-b border-line last:border-0">
                            <td class="py-2.5 pl-5 text-[13px] whitespace-nowrap">{{ $m->occurred_at->translatedFormat('j M, H.i') }}</td>
                            @if ($branches->count() > 1)<td class="text-[13px]">{{ $m->branch->shortName() }}</td>@endif
                            <td class="text-[13px]">{{ $m->item->name }}</td>
                            <td class="text-[13px]">{{ $m->typeLabel() }}</td>
                            <td @class(['text-right font-semibold', 'text-brand-600' => $m->quantity > 0, 'text-rose-ink' => $m->quantity < 0])>{{ $m->quantity > 0 ? '+' : '' }}{{ $m->quantity }}</td>
                            <td class="text-[12px] text-muted">{{ $m->note }}</td>
                            <td class="pr-5 text-[12px] text-muted">{{ $m->user?->name ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="py-12 text-center text-muted">Belum ada mutasi di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>

    @else
        <div class="mt-5 flex flex-wrap items-end gap-3">
            <div><label class="label text-xs">Dari</label><input type="date" wire:model.live="from" class="input min-h-10"></div>
            <div><label class="label text-xs">Sampai</label><input type="date" wire:model.live="to" class="input min-h-10"></div>
            <p class="ml-auto text-xs text-muted">{{ $context->label() }} · {{ $r['from']->translatedFormat('j M Y') }} – {{ $r['to']->translatedFormat('j M Y') }}</p>
        </div>
        <div class="mt-4 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach (['buy_value' => 'Pembelian', 'revenue' => 'Penjualan', 'profit' => 'Laba kotor penjualan', 'use_value' => 'Nilai pemakaian'] as $k => $label)
                <div @class(['card p-4', 'bg-brand-600 text-white' => $k === 'profit'])>
                    <p @class(['text-[13px]', 'text-white/85' => $k === 'profit', 'text-muted' => $k !== 'profit'])>{{ $label }}</p>
                    <p class="mt-2 text-lg font-semibold">@rupiah($r['totals'][$k])</p>
                </div>
            @endforeach
        </div>
        <section class="card mt-4 overflow-x-auto">
            <table class="w-full min-w-[860px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr><th class="py-3 pl-5">Barang</th><th class="text-right">Beli</th><th class="text-right">Nilai beli</th><th class="text-right">Jual</th><th class="text-right">Penjualan</th><th class="text-right">HPP</th><th class="text-right">Laba kotor</th><th class="pr-5 text-right">Pakai</th></tr>
                </thead>
                <tbody>
                    @forelse ($r['rows'] as $x)
                        <tr class="border-b border-line last:border-0">
                            <td class="py-2.5 pl-5"><p class="text-[13px] font-medium">{{ $x['item']->name }}</p><p class="text-[11px] text-muted">{{ Item::CATEGORIES[$x['item']->category] ?? '' }} · {{ $x['item']->unit }}</p></td>
                            <td class="text-right">{{ $x['buy_qty'] ?: '—' }}</td>
                            <td class="text-right">@rupiah($x['buy_value'])</td>
                            <td class="text-right">{{ $x['sell_qty'] ?: '—' }}</td>
                            <td class="text-right">@rupiah($x['revenue'])</td>
                            <td class="text-right text-muted">@rupiah($x['cogs'])</td>
                            <td @class(['text-right font-semibold', 'text-rose-ink' => $x['profit'] < 0])>@rupiah($x['profit'])</td>
                            <td class="pr-5 text-right">{{ $x['use_qty'] ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="py-12 text-center text-muted">Belum ada transaksi stok di periode ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-muted">Penjualan = obat/barang di tagihan yang sudah lunas. HPP memakai harga beli rata-rata tertimbang. Pembelian juga tercatat di Laporan › Pengeluaran kategori "Pembelian obat & bahan".</p>
    @endif

    {{-- Form barang --}}
    @if ($showItemForm)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <form wire:submit="saveItem" class="card max-h-[92dvh] w-full max-w-md overflow-y-auto p-6">
                <h3 class="text-lg font-semibold">{{ $editingId ? 'Ubah barang' : 'Barang baru' }}</h3>
                <div class="mt-5 space-y-4">
                    <div class="grid grid-cols-[110px_1fr] gap-3">
                        <div><label class="label">Kode</label><input type="text" wire:model="itemForm.code" maxlength="20" class="input uppercase"><x-error for="itemForm.code" /></div>
                        <div><label class="label">Nama</label><input type="text" wire:model="itemForm.name" class="input"><x-error for="itemForm.name" /></div>
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="col-span-2"><label class="label">Kategori</label>
                            <select wire:model="itemForm.category" class="input">@foreach (Item::CATEGORIES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                        </div>
                        <div><label class="label">Satuan</label><input type="text" wire:model="itemForm.unit" maxlength="20" class="input" placeholder="tablet"><x-error for="itemForm.unit" /></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">Harga beli</label><x-money model="itemForm.buy_price" /><x-error for="itemForm.buy_price" /></div>
                        <div><label class="label">Stok minimum</label><input type="number" wire:model="itemForm.min_stock" min="0" class="input"><x-error for="itemForm.min_stock" /></div>
                    </div>
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model.live="itemForm.sellable" class="size-4 accent-brand-600"> Dijual ke pasien</label>
                    @if ($itemForm['sellable'])
                        <div><label class="label">Harga jual / {{ $itemForm['unit'] ?: 'satuan' }}</label><x-money model="itemForm.sell_price" /><x-error for="itemForm.sell_price" /></div>
                    @endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="itemForm.is_active" class="size-4 accent-brand-600"> Aktif</label>
                </div>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="submit" class="btn-primary flex-1">Simpan</button>
                    <button type="button" wire:click="$set('showItemForm', false)" class="btn-ghost">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
