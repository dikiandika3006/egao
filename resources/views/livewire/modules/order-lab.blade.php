@php use App\Models\LabOrder; @endphp

<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canEdit)<button type="button" wire:click="create" class="btn-primary">+ Order lab</button>@endif
    </x-page-header>
    <x-error for="form.lab_name" />

    <div class="mt-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="card p-4"><p class="text-[13px] text-muted">Sedang di lab / belum dipasang</p><p class="mt-2 text-xl font-semibold">{{ $stats['active'] }}</p></div>
        <div @class(['card p-4', 'bg-rose-soft' => $stats['overdue'] > 0])><p class="text-[13px] text-muted">Lewat perkiraan jadi</p><p @class(['mt-2 text-xl font-semibold', 'text-rose-ink' => $stats['overdue'] > 0])>{{ $stats['overdue'] }}</p></div>
        <div class="card bg-amber-soft p-4"><p class="text-[13px] text-amber-ink">Belum dibayar ke lab</p><p class="mt-2 text-xl font-semibold text-amber-ink">@rupiah($stats['unpaid'])</p></div>
        <div class="card p-4"><p class="text-[13px] text-muted">Dibayar ke lab bulan ini</p><p class="mt-2 text-xl font-semibold">@rupiah($stats['paidMonth'])</p></div>
    </div>

    <div class="mt-6 grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 px-5 pt-5">
                <div class="flex rounded-lg bg-mist p-1 text-xs">
                    @foreach (['aktif' => 'Aktif', 'belum_bayar' => 'Belum dibayar', 'semua' => 'Semua'] as $k => $label)
                        <button type="button" wire:click="$set('filter', '{{ $k }}')" @class(['min-h-8 rounded-md px-3', 'bg-white font-semibold shadow-sm' => $filter === $k, 'text-muted' => $filter !== $k])>{{ $label }}</button>
                    @endforeach
                </div>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari pasien, lab, pekerjaan…" class="input min-h-10 max-w-xs flex-1">
            </div>

            <ul class="mt-4 divide-y divide-line">
                @forelse ($orders as $o)
                    <li wire:key="o{{ $o->id }}" class="px-5 py-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-medium">{{ $o->work }}@if ($o->tooth) <span class="text-sm font-normal text-muted">· gigi {{ $o->tooth }}</span>@endif</p>
                                <p class="mt-1 text-[12px] text-muted">
                                    {{ $o->patient->name }} ({{ $o->patient->rmCode() }}) · {{ $o->lab_name }}
                                    @if ($o->doctor) · {{ $o->doctor->name }} @endif
                                    @if ($context->isAll()) · {{ $o->branch->shortName() }} @endif
                                </p>
                                <p class="mt-1 text-[12px] text-muted">
                                    Kirim {{ $o->sent_at->translatedFormat('j M') }}
                                    @if ($o->due_at) · perkiraan jadi <span @class(['font-semibold text-rose-ink' => $o->isOverdue()])>{{ $o->due_at->translatedFormat('j M') }}</span> @endif
                                    @if ($o->received_at) · diterima {{ $o->received_at->translatedFormat('j M') }} @endif
                                </p>
                                @if ($o->note)<p class="mt-1 text-[12px] text-muted italic">{{ $o->note }}</p>@endif
                            </div>
                            <div class="text-right">
                                <p class="font-semibold">@rupiah($o->cost)</p>
                                <p class="mt-1 text-[11px] {{ $o->paid_at ? 'text-brand-600' : 'text-amber-ink' }}">{{ $o->paid_at ? 'Dibayar '.$o->paid_at->translatedFormat('j M') : 'Belum dibayar ke lab' }}</p>
                            </div>
                        </div>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="pill {{ $o->statusClass() }}">{{ $o->statusLabel() }}</span>
                            @if ($o->status === 'dikirim')
                                @if ($canEdit)<button type="button" wire:click="setStatus({{ $o->id }}, 'diterima')" class="btn-ghost min-h-9 px-3 text-xs">Sudah diterima dari lab</button>@endif
                            @elseif ($o->status === 'diterima')
                                @if ($canEdit)<button type="button" wire:click="setStatus({{ $o->id }}, 'dipasang')" class="btn-ghost min-h-9 px-3 text-xs">Sudah dipasang</button>@endif
                            @endif
                            @if (! $o->paid_at && $o->status !== 'batal')
                                @if ($canEdit)<button type="button" wire:click="markPaid({{ $o->id }})" wire:confirm="Catat pembayaran @rupiah($o->cost) ke {{ $o->lab_name }}? Akan masuk ke Pengeluaran (Lab gigi) hari ini." class="btn-soft min-h-9 px-3 text-xs">Bayar ke lab</button>@endif
                            @elseif ($o->paid_at)
                                @if ($canEdit)<button type="button" wire:click="unmarkPaid({{ $o->id }})" wire:confirm="Batalkan catatan pembayaran? Pengeluaran terkait akan dihapus." class="min-h-9 px-2 text-xs text-muted hover:text-rose-ink">Batalkan bayar</button>@endif
                            @endif
                            @if ($canEdit)<button type="button" wire:click="edit({{ $o->id }})" class="min-h-9 px-2 text-xs text-brand-600 hover:underline">Ubah</button>@endif
                            @if (in_array($o->status, ['dikirim', 'diterima'], true) && ! $o->paid_at)
                                @if ($canEdit)<button type="button" wire:click="setStatus({{ $o->id }}, 'batal')" wire:confirm="Batalkan order ini?" class="min-h-9 px-2 text-xs text-muted hover:text-rose-ink">Batal</button>@endif
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="px-5 py-12 text-center text-sm text-muted">Belum ada order lab.</li>
                @endforelse
            </ul>
        </section>

        <aside class="space-y-6">
            <section class="card p-5">
                <h2 class="font-semibold">Utang ke lab</h2>
                <ul class="mt-3 divide-y divide-line text-sm">
                    @forelse ($unpaidByLab as $l)
                        <li class="flex justify-between py-2.5"><span>{{ $l->lab_name }} <span class="text-xs text-muted">({{ $l->n }})</span></span><b>@rupiah($l->total)</b></li>
                    @empty
                        <li class="py-2 text-muted">Semua sudah dibayar.</li>
                    @endforelse
                </ul>
            </section>
            <div class="rounded-2xl bg-leaf-100 p-5 text-sm text-brand-600">
                <h3 class="font-semibold">Cara pakai</h3>
                <ol class="mt-2 list-decimal space-y-1 pl-5">
                    <li>Dokter mencetak/mengambil cetakan gigi → buat <b>order lab</b> (pasien, lab, pekerjaan, biaya dari lab).</li>
                    <li>Barang datang → <b>Sudah diterima</b>; setelah dipasang ke pasien → <b>Sudah dipasang</b>.</li>
                    <li>Saat klinik membayar tagihan lab → <b>Bayar ke lab</b>. Otomatis masuk Pengeluaran "Lab gigi".</li>
                </ol>
                <p class="mt-2 text-xs">Biaya ke pasien tetap ditagihkan lewat tindakan di rekam medis/kasir.</p>
            </div>
        </aside>
    </div>

    {{-- Form order --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <form wire:submit="save" class="card max-h-[92dvh] w-full max-w-lg overflow-y-auto p-6">
                <h3 class="text-lg font-semibold">{{ $editingId ? 'Ubah order lab' : 'Order lab baru' }}</h3>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="label">Pasien</label>
                        @if ($selectedPatient)
                            <div class="flex items-center justify-between rounded-lg bg-leaf-100 px-4 py-3 text-sm">
                                <span><b>{{ $selectedPatient->name }}</b> · {{ $selectedPatient->rmCode() }}</span>
                                <button type="button" wire:click="$set('form.patient_id', null)" class="text-xs text-brand-600 hover:underline">Ganti</button>
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="patientSearch" placeholder="Cari nama, HP, atau no. RM…" class="input">
                            @if ($patientResults->isNotEmpty())
                                <ul class="mt-1 rounded-xl border border-line bg-white p-1">
                                    @foreach ($patientResults as $p)
                                        <li><button type="button" wire:click="pickPatient({{ $p->id }})" class="flex min-h-10 w-full items-center justify-between rounded-lg px-3 text-left text-sm hover:bg-mist"><span>{{ $p->name }}</span><span class="text-xs text-muted">{{ $p->rmCode() }}</span></button></li>
                                    @endforeach
                                </ul>
                            @endif
                        @endif
                        <x-error for="form.patient_id" />
                    </div>
                    @unless ($editingId)<x-branch-pick :context="$context" />@endunless
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Nama lab</label>
                            <input type="text" wire:model="form.lab_name" list="lab-names" class="input" placeholder="Mis. Lab Sinar Dental">
                            <datalist id="lab-names">@foreach ($labNames as $n)<option value="{{ $n }}">@endforeach</datalist>
                            <x-error for="form.lab_name" />
                        </div>
                        <div>
                            <label class="label">Dokter</label>
                            <select wire:model="form.doctor_id" class="input"><option value="">—</option>@foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
                        </div>
                    </div>
                    <div class="grid grid-cols-[1fr_110px] gap-3">
                        <div><label class="label">Pekerjaan</label><input type="text" wire:model="form.work" class="input" placeholder="Mahkota PFM, gigi tiruan akrilik…"><x-error for="form.work" /></div>
                        <div><label class="label">Gigi</label><input type="text" wire:model="form.tooth" maxlength="30" class="input" placeholder="46"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label">Tanggal kirim</label><input type="date" wire:model="form.sent_at" class="input"><x-error for="form.sent_at" /></div>
                        <div><label class="label">Perkiraan jadi</label><input type="date" wire:model="form.due_at" class="input"><x-error for="form.due_at" /></div>
                    </div>
                    <div><label class="label">Biaya dari lab</label><x-money model="form.cost" /><x-error for="form.cost" /></div>
                    <div><label class="label">Catatan</label><input type="text" wire:model="form.note" maxlength="255" class="input" placeholder="Warna A2, dll"></div>
                </div>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="submit" class="btn-primary flex-1">Simpan</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-ghost">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
