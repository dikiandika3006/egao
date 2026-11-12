<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        <button type="button" wire:click="create" class="btn-primary">+ &nbsp;Catat pengeluaran</button>
    </x-page-header>

    <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="shiftMonth(-1)" class="btn-ghost px-3" aria-label="Bulan sebelumnya">←</button>
            <p class="min-w-[150px] text-center text-sm font-semibold">{{ $start->translatedFormat('F Y') }}</p>
            <button type="button" wire:click="shiftMonth(1)" class="btn-ghost px-3" aria-label="Bulan berikutnya">→</button>
        </div>
        <select wire:model.live="category" class="input max-w-xs">
            <option value="">Semua kategori</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
        </select>
    </div>

    <div class="mt-4 grid gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
        <section class="card overflow-hidden">
            <ul class="divide-y divide-line">
                @forelse ($rows as $e)
                    <li wire:key="e-{{ $e->id }}" class="flex flex-wrap items-center gap-3 px-5 py-3.5">
                        <div class="w-14 shrink-0 text-center">
                            <p class="text-lg font-semibold leading-none">{{ $e->date->day }}</p>
                            <p class="text-[10px] text-muted uppercase">{{ $e->date->translatedFormat('M') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">{{ $e->description ?: $e->category->name }}</p>
                            <p class="mt-0.5 text-[12px] text-muted">{{ $e->category->name }} · {{ $e->branch->shortName() }}
                                @if ($e->category->is_system)<span class="pill ml-1 bg-indigo-soft px-2 py-0.5 text-[10px] text-indigo-ink">Otomatis</span>@endif
                                @if ($e->proof_path) · <button type="button" wire:click="downloadProof({{ $e->id }})" class="text-brand-600 hover:underline">Bukti</button>@endif
                            </p>
                        </div>
                        <p class="font-semibold">@rupiah($e->amount)</p>
                        @unless ($e->category->is_system)
                            <button type="button" wire:click="edit({{ $e->id }})" class="text-xs text-brand-600 hover:underline">Ubah</button>
                        @endunless
                    </li>
                @empty
                    <li class="py-14 text-center text-sm text-muted">Belum ada pengeluaran di bulan ini.</li>
                @endforelse
            </ul>
        </section>

        <aside class="space-y-4">
            <div class="rounded-2xl bg-brand-600 p-5 text-white">
                <p class="text-sm text-white/85">Total {{ $start->translatedFormat('F') }}</p>
                <p class="mt-2 text-[28px] font-semibold tracking-tight">@rupiah($total)</p>
                <p class="mt-2 text-xs text-white/75">{{ $context->label() }}</p>
            </div>
            <div class="card p-5">
                <h2 class="font-semibold">Per kategori</h2>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($byCategory as $row)
                        <li class="flex justify-between py-2.5"><span>{{ $row['name'] }}</span><b>@rupiah($row['total'])</b></li>
                    @empty
                        <li class="py-2 text-muted">—</li>
                    @endforelse
                </ul>
            </div>
        </aside>
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Ubah pengeluaran' : 'Catat pengeluaran'">
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label">Cabang</label>
                    <select wire:model="expense.branch_id" class="input">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->shortName() }}</option>@endforeach
                    </select>
                    <x-error for="expense.branch_id" />
                </div>
                <div>
                    <label class="label">Tanggal</label>
                    <input type="date" wire:model="expense.date" class="input" max="{{ today()->toDateString() }}">
                    <x-error for="expense.date" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Kategori</label>
                    <select wire:model="expense.expense_category_id" class="input">
                        <option value="">Pilih kategori</option>
                        @foreach ($categories->where('is_system', false) as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                    </select>
                    <x-error for="expense.expense_category_id" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Nominal (Rp)</label>
                    <x-money model="expense.amount" />
                    <x-error for="expense.amount" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Keterangan</label>
                    <input type="text" wire:model="expense.description" class="input">
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Bukti / nota <span class="font-normal text-muted">(JPG, PNG, PDF ≤ 4 MB)</span></label>
                    <input type="file" wire:model="proof" accept="image/*,application/pdf" class="block w-full text-sm">
                    <div wire:loading wire:target="proof" class="mt-1 text-xs text-muted">Mengunggah…</div>
                    <x-error for="proof" />
                </div>
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2">
                    @if ($editingId)
                        <button type="button" wire:click="delete({{ $editingId }})" wire:confirm="Hapus pengeluaran ini?" class="btn-ghost text-rose-ink">Hapus</button>
                    @else
                        <span></span>
                    @endif
                    <button class="btn-primary" wire:loading.attr="disabled" wire:target="save,proof">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
