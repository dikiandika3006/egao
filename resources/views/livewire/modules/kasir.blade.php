@php use App\Models\Invoice; use App\Support\Format; @endphp
<div wire:poll.30s class="w-full">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canEdit)<button type="button" wire:click="openDirectSale" class="btn-soft">+ Penjualan langsung</button>@endif
    </x-page-header>

    <div class="mt-7 grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_400px]">
        <section class="card overflow-hidden">
            <div class="flex gap-8 border-b border-line px-5 pt-4 text-sm">
                @foreach (['tagihan' => 'Menunggu pembayaran ('.$pending->count().')', 'piutang' => 'Piutang ('.$receivables->count().')', 'lunas' => 'Lunas hari ini ('.$paidToday->count().')'] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                        '-mb-px border-b-2 pb-3',
                        'border-leaf-500 font-semibold text-brand-600' => $tab === $key,
                        'border-transparent text-muted' => $tab !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
            <ul class="divide-y divide-line">
                @forelse (match ($tab) { 'lunas' => $paidToday, 'piutang' => $receivables, default => $pending } as $inv)
                    <li wire:key="i-{{ $inv->id }}">
                        <button type="button" wire:click="open({{ $inv->id }})" @class(['flex w-full flex-wrap items-center gap-3 px-5 py-4 text-left hover:bg-mist', 'bg-leaf-50' => $invoice?->id === $inv->id])>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold">{{ $inv->payerName() }}@if ($inv->isDirectSale()) <span class="ml-1 rounded bg-orange-soft px-1.5 py-0.5 text-[10px] font-semibold text-orange-ink">Penjualan langsung</span>@endif</p>
                                <p class="mt-0.5 text-[12px] text-muted">{{ $inv->payerPatient()?->rmCode() ?? 'Tanpa RM' }}@if ($context->isAll()) · {{ $inv->branch->shortName() }}@endif
                                    @if ($tab === 'lunas') · {{ $inv->paid_at->format('H.i') }} · {{ $inv->receipts->first()?->code }}@endif
                                    @if ($tab === 'piutang') · {{ $inv->created_at->translatedFormat('j M Y') }} · {{ (int) $inv->created_at->startOfDay()->diffInDays(today()) }} hari · dibayar @rupiah($inv->paid)@endif</p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold">@rupiah($tab === 'lunas' ? $inv->total : $inv->outstanding())</p>
                                <p class="text-[11px] text-muted">{{ $inv->statusLabel() }}</p>
                            </div>
                        </button>
                    </li>
                @empty
                    <li class="py-14 text-center text-sm text-muted">{{ match ($tab) { 'lunas' => 'Belum ada pembayaran lunas hari ini.', 'piutang' => 'Tidak ada piutang.', default => 'Tidak ada tagihan yang menunggu.' } }}</li>
                @endforelse
            </ul>
            @if ($tab === 'piutang' && $receivables->isNotEmpty())
                <p class="flex justify-between border-t border-line px-5 py-3 text-sm"><span class="text-muted">Total piutang</span><b>@rupiah($receivables->sum(fn ($i) => $i->outstanding()))</b></p>
            @endif
            @if ($tab === 'lunas' && $paidToday->isNotEmpty())
                <p class="flex justify-between border-t border-line px-5 py-3 text-sm"><span class="text-muted">Total lunas hari ini</span><b>@rupiah($totalToday)</b></p>
            @endif
        </section>

        @if ($invoice)
            <aside class="card p-5 lg:sticky lg:top-[96px] lg:self-start">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] text-muted">{{ $invoice->payerPatient()?->rmCode() ?? 'Tanpa RM' }} · {{ $invoice->branch->shortName() }}</p>
                        <h2 class="mt-1 text-xl font-semibold">{{ $invoice->payerName() }}</h2>
                        @if ($invoice->payerPatient()?->referred_by)
                            <p class="mt-1"><span class="pill bg-sky-soft text-sky-ink">Rujukan {{ $invoice->payerPatient()->referred_by }}</span></p>
                        @endif
                        @if ($invoice->visit)
                            <p class="mt-1 text-sm text-muted">{{ $invoice->visit->doctor->name }} · {{ $invoice->visit->visit_date->translatedFormat('j M Y') }}</p>
                            @if ($invoice->visit->assistantNames())
                                <p class="mt-1 text-xs text-muted">Asisten · {{ $invoice->visit->assistantNames() }}</p>
                            @endif
                        @else
                            <p class="mt-1 text-sm text-muted">Penjualan langsung · {{ $invoice->created_at->translatedFormat('j M Y') }}</p>
                            @if ($invoice->items->isEmpty() && $invoice->paid === 0)
                                <button type="button" wire:click="discardDirectSale" wire:confirm="Hapus penjualan kosong ini?" class="mt-1 text-xs text-rose-ink hover:underline">Hapus (belum ada barang)</button>
                            @endif
                        @endif
                    </div>
                    <button type="button" wire:click="close" class="btn-ghost min-h-9 px-3" aria-label="Tutup">✕</button>
                </div>

                <ul class="mt-4 divide-y divide-line text-sm">
                    @foreach ($invoice->items as $i)
                        <li class="flex justify-between gap-3 py-2.5">
                            <span>{{ $i->name }}@if ($i->tooth) <span class="text-xs text-muted">gigi {{ $i->tooth }}</span>@endif @if ($i->quantity > 1)<span class="text-xs text-muted">×{{ $i->quantity }}</span>@endif
                                @if ($i->isLowered())<span class="block text-[11px] text-amber-ink">Di bawah standar @rupiah($i->standard_price){{ $i->price_reason ? ' — '.$i->price_reason : '' }}</span>@endif</span>
                            <span class="flex items-center gap-2">
                                @rupiah($i->amount)
                                @if ($i->is_admin_fee && $canEdit && $invoice->paid === 0 && $invoice->status === Invoice::STATUS_UNPAID)
                                    <button type="button" wire:click="removeAdminFee({{ $i->id }})" wire:confirm="{{ $i->fee_kind === \App\Models\InvoiceItem::FEE_REGISTRATION ? 'Hapus biaya pendaftaran? Pasien dicatat sebagai pasien lama dan tidak ditagih pendaftaran lagi.' : 'Hapus biaya admin dari tagihan ini? Biaya admin tidak akan ditagih ulang di tagihan ini.' }}" class="text-muted hover:text-rose-ink" title="Hapus biaya admin">✕</button>
                                @endif
                                @if ($i->item_id && $goodsOptions->isNotEmpty())
                                    <button type="button" wire:click="removeGoods({{ $i->id }})" class="text-muted hover:text-rose-ink" title="Hapus barang">✕</button>
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>

                {{-- Jual obat/barang (stok dipotong saat lunas) --}}
                @if ($goodsOptions->isNotEmpty())
                    <details class="mt-3 rounded-xl bg-mist p-3 text-sm" @if ($errors->has('goods.*')) open @endif>
                        <summary class="cursor-pointer font-medium">+ Tambah obat / barang</summary>
                        <div class="mt-3 grid grid-cols-[1fr_76px] gap-2">
                            <select wire:model="goods.item_id" class="input">
                                <option value="">Pilih barang…</option>
                                @foreach ($goodsOptions->groupBy('category') as $cat => $group)
                                    <optgroup label="{{ \App\Models\Item::CATEGORIES[$cat] ?? $cat }}">
                                        @foreach ($group as $g)
                                            @php $left = (int) ($g->stocks->first()?->quantity ?? 0); @endphp
                                            <option value="{{ $g->id }}" @disabled($left <= 0)>{{ $g->name }} — @rupiah($g->sell_price) · stok {{ $left }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>
                            <input type="number" wire:model="goods.quantity" min="1" max="999" class="input" aria-label="Jumlah">
                            <div class="col-span-2"><x-error for="goods.item_id" /><x-error for="goods.quantity" /></div>
                            <button type="button" wire:click="addGoods" class="btn-ghost col-span-2 w-full bg-white">Tambahkan ke tagihan</button>
                        </div>
                    </details>
                @endif
                <x-error for="stock" />
                <dl class="mt-2 space-y-1.5 border-t border-line pt-3 text-sm">
                    <div class="flex justify-between"><dt class="text-muted">Subtotal</dt><dd>@rupiah($invoice->subtotal)</dd></div>
                    @if ($invoice->discount)
                        <div class="flex justify-between"><dt class="text-muted">Diskon <span class="text-xs">({{ $invoice->discount_reason }})</span></dt><dd>−@rupiah($invoice->discount)</dd></div>
                    @endif
                    <div class="flex justify-between text-base font-semibold"><dt>Total</dt><dd>@rupiah($invoice->total)</dd></div>
                    @if ($invoice->paid)
                        <div class="flex justify-between"><dt class="text-muted">Sudah dibayar</dt><dd>@rupiah($invoice->paid)</dd></div>
                        <div class="flex justify-between font-semibold"><dt>Sisa</dt><dd>@rupiah($invoice->outstanding())</dd></div>
                    @endif
                </dl>

                {{-- Diskon diberikan dokter di pemeriksaan; di Kasir hanya Owner yang bisa mengubah. --}}
                @if ($canDiscount && $invoice->status === Invoice::STATUS_UNPAID && $invoice->paid === 0)
                    <details class="mt-4 rounded-xl bg-mist p-3 text-sm" @if ($invoice->discount || $errors->has('pay.discount*')) open @endif>
                        <summary class="cursor-pointer font-medium">Diskon</summary>
                        <div class="mt-3 space-y-2">
                            <x-money model="pay.discount" placeholder="0" />
                            <p class="text-xs text-muted">Maksimal @rupiah($maxDiscount)</p>
                            <x-error for="pay.discount" />
                            <input type="text" wire:model="pay.discount_reason" class="input" placeholder="Alasan diskon">
                            <x-error for="pay.discount_reason" />
                            <button type="button" wire:click="applyDiscount" class="btn-ghost w-full bg-white">Terapkan diskon</button>
                        </div>
                    </details>
                @endif

                @if (in_array($invoice->status, [Invoice::STATUS_UNPAID, Invoice::STATUS_PARTIAL], true))
                    <form wire:submit="submitPayment" class="mt-4 space-y-3" x-data>
                        <div>
                            <label class="label">Metode</label>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach ($methods as $key => $label)
                                    <label class="cursor-pointer">
                                        <input type="radio" wire:model.live="pay.method" value="{{ $key }}" class="peer sr-only">
                                        <span class="flex min-h-10 items-center justify-center rounded-lg border border-line px-2 text-center text-xs peer-checked:border-brand-600 peer-checked:bg-leaf-100 peer-checked:font-semibold peer-checked:text-brand-600">{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                        <div>
                            <label class="label">Jumlah bayar</label>
                            <x-money model="pay.amount" live />
                            <x-error for="pay.amount" />
                        </div>
                        @if ($pay['method'] === 'tunai')
                            <div>
                                <label class="label">Uang diterima</label>
                                <x-money model="pay.cash" live />
                                <x-error for="pay.cash" />
                                @if (filled($pay['cash']) && (int) $pay['cash'] >= (int) $pay['amount'])
                                    <p class="mt-1.5 text-sm">Kembalian: <b>@rupiah((int) $pay['cash'] - (int) $pay['amount'])</b></p>
                                @endif
                            </div>
                        @endif
                        <button class="btn-primary w-full" wire:loading.attr="disabled">Terima pembayaran</button>
                    </form>
                @endif

                @if ($invoice->payments->isNotEmpty())
                    <h3 class="mt-5 text-sm font-semibold">Pembayaran</h3>
                    <ul class="mt-2 space-y-1 text-[13px] text-muted">
                        @foreach ($invoice->payments as $p)
                            <li class="flex justify-between"><span>{{ $p->paid_at->format('d/m H.i') }} · {{ $allMethods[$p->method] ?? $p->method }} · {{ $p->receiver?->name }}</span><span class="text-brand-700">@rupiah($p->amount)</span></li>
                        @endforeach
                    </ul>
                @endif

                @foreach ($invoice->receipts as $r)
                    <a href="{{ route('kasir.kwitansi', $r) }}" target="_blank" class="btn-soft mt-4 w-full">Cetak kwitansi {{ $r->code }}</a>
                @endforeach
            </aside>
        @endif
    </div>

    {{-- Penjualan langsung: tanpa kunjungan (pasien rujukan / pembeli umum). Setelah dibuat, tambah barang di panel tagihan. --}}
    @if ($showDirectSale)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <form wire:submit="createDirectSale" class="card w-full max-w-md p-6">
                <h3 class="text-lg font-semibold">Penjualan langsung</h3>
                <p class="mt-1 text-sm text-muted">Untuk pembelian barang tanpa pemeriksaan, mis. dental floss atau behel per satuan untuk pasien rujukan.</p>
                <div class="mt-5 space-y-4">
                    <x-branch-pick :context="$context" />
                    <div>
                        <label class="label">Pembeli</label>
                        @if ($directPatient)
                            <div class="flex items-center justify-between rounded-lg bg-leaf-100 px-4 py-3 text-sm">
                                <span><b>{{ $directPatient->name }}</b> · {{ $directPatient->rmCode() }}</span>
                                <button type="button" wire:click="$set('direct.patient_id', null)" class="text-xs text-brand-600 hover:underline">Ganti</button>
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="directSearch" placeholder="Cari pasien terdaftar (opsional)…" class="input">
                            @if ($directResults->isNotEmpty())
                                <ul class="mt-1 rounded-xl border border-line bg-white p-1">
                                    @foreach ($directResults as $p)
                                        <li><button type="button" wire:click="$set('direct.patient_id', {{ $p->id }})" class="flex min-h-10 w-full items-center justify-between rounded-lg px-3 text-left text-sm hover:bg-mist"><span>{{ $p->name }}</span><span class="text-xs text-muted">{{ $p->rmCode() }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                            <input type="text" wire:model="direct.buyer_name" maxlength="255" class="input mt-2" placeholder="…atau ketik nama pembeli, mis. drg. Rina (rujukan)">
                        @endif
                        <x-error for="direct.buyer_name" />
                    </div>
                </div>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="submit" class="btn-primary flex-1">Buat tagihan</button>
                    <button type="button" wire:click="$set('showDirectSale', false)" class="btn-ghost">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
