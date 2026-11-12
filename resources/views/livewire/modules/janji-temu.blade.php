@php use App\Models\Appointment; use App\Support\Format; @endphp

<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canWrite)
            <button type="button" wire:click="create" class="btn-primary">+ Buat janji</button>
        @endif
    </x-page-header>
    <x-error for="form.date" />

    {{-- Navigasi hari/bulan + pilihan tampilan --}}
    <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-2">
            @if ($view === 'bulan')
                <button type="button" wire:click="shiftMonth(-1)" class="btn-ghost px-3" aria-label="Bulan sebelumnya">←</button>
                <p class="min-w-[150px] text-center text-base font-semibold">{{ $day->translatedFormat('F Y') }}</p>
                <button type="button" wire:click="shiftMonth(1)" class="btn-ghost px-3" aria-label="Bulan berikutnya">→</button>
            @else
                <button type="button" wire:click="shiftDay(-1)" class="btn-ghost px-3" aria-label="Hari sebelumnya">←</button>
                <input type="date" wire:model.live="date" class="input min-h-10 w-auto">
                <button type="button" wire:click="shiftDay(1)" class="btn-ghost px-3" aria-label="Hari berikutnya">→</button>
            @endif
            @unless ($day->isToday())
                <button type="button" wire:click="goToday" class="btn-soft min-h-10 px-3 text-xs">Hari ini</button>
            @endunless
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <p class="text-xs text-muted">{{ $context->label() }} · {{ $upcoming }} janji dalam 7 hari ke depan</p>
            <div class="flex gap-1 rounded-lg bg-white p-1 text-sm" role="tablist" aria-label="Tampilan">
                @foreach (['minggu' => 'Minggu', 'bulan' => 'Bulan'] as $key => $label)
                    <button type="button" role="tab" aria-selected="{{ $view === $key ? 'true' : 'false' }}" wire:click="setView('{{ $key }}')" @class([
                        'rounded-md px-3 py-1.5 font-medium',
                        'bg-brand-600 text-white' => $view === $key,
                        'text-muted hover:text-brand-700' => $view !== $key,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
    </div>

    @if ($view === 'bulan')
        {{-- Kalender bulanan --}}
        <section class="card mt-4 overflow-hidden">
            <div class="grid grid-cols-7 border-b border-line bg-canvas/70 text-center text-[11px] font-medium text-muted uppercase">
                @foreach (['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'] as $dow)
                    <div class="py-2.5">{{ $dow }}</div>
                @endforeach
            </div>
            <div class="grid grid-cols-7">
                @foreach ($month as $cell)
                    @php
                        $d = $cell['date'];
                        $inMonth = $d->isSameMonth($day);
                        $selected = $d->isSameDay($day);
                        $active = $cell['items']->whereIn('status', [Appointment::SCHEDULED, Appointment::ARRIVED]);
                    @endphp
                    <button type="button" wire:key="m-{{ $d->toDateString() }}" wire:click="$set('date', '{{ $d->toDateString() }}')" @class([
                        'flex min-h-[64px] flex-col items-stretch gap-1 border-line p-1.5 text-left align-top transition sm:min-h-[104px] sm:p-2',
                        'border-r' => $loop->iteration % 7 !== 0,
                        'border-b' => $loop->remaining >= 7,
                        'bg-leaf-50 ring-2 ring-brand-600 ring-inset' => $selected,
                        'hover:bg-mist' => ! $selected,
                        'bg-canvas/50 text-muted/70' => ! $inMonth && ! $selected,
                    ])>
                        <span class="flex items-center justify-between">
                            <span @class([
                                'flex size-6 items-center justify-center rounded-full text-[13px] font-semibold',
                                'bg-brand-600 text-white' => $d->isToday(),
                            ])>{{ $d->day }}</span>
                            @if ($active->count())
                                <span class="rounded-full bg-leaf-100 px-1.5 text-[10px] font-semibold text-brand-600 sm:hidden">{{ $active->count() }}</span>
                            @endif
                        </span>
                        {{-- Nama pasien (layar lebar); di ponsel cukup jumlahnya --}}
                        <span class="hidden flex-col gap-0.5 sm:flex">
                            @foreach ($cell['items']->take(3) as $a)
                                <span @class([
                                    'truncate rounded px-1.5 py-0.5 text-[11px] leading-tight',
                                    'bg-sky-soft text-sky-ink' => $a->status === Appointment::SCHEDULED && $a->source !== 'kontrol',
                                    'bg-indigo-soft text-indigo-ink' => $a->status === Appointment::SCHEDULED && $a->source === 'kontrol',
                                    'bg-leaf-100 text-brand-600' => $a->status === Appointment::ARRIVED,
                                    'bg-mist text-muted line-through' => $a->status === Appointment::NO_SHOW,
                                ]) title="{{ $a->time ? Format::jam($a->time).' · ' : '' }}{{ $a->patient->name }}">
                                    @if ($a->time)<span class="font-semibold">{{ Format::jam($a->time) }}</span> @endif{{ $a->patient->name }}
                                </span>
                            @endforeach
                            @if ($cell['items']->count() > 3)
                                <span class="px-1.5 text-[11px] font-medium text-muted">+{{ $cell['items']->count() - 3 }} lainnya</span>
                            @endif
                        </span>
                    </button>
                @endforeach
            </div>
        </section>
        <div class="mt-2 flex flex-wrap gap-3 text-[11px] text-muted">
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-sky-soft ring-1 ring-sky-ink/30"></span>Terjadwal</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-indigo-soft ring-1 ring-indigo-ink/30"></span>Kontrol dokter</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-leaf-100 ring-1 ring-brand-600/30"></span>Sudah datang</span>
            <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-mist ring-1 ring-muted/30"></span>Tidak datang</span>
            <span>· Klik tanggal untuk melihat detail di bawah.</span>
        </div>
    @else
    <div class="mt-4 grid grid-cols-7 gap-2">
        @foreach ($week as $w)
            <button type="button" wire:click="$set('date', '{{ $w['date']->toDateString() }}')" @class([
                'rounded-xl px-2 py-2.5 text-center transition',
                'bg-brand-600 text-white' => $w['date']->isSameDay($day),
                'bg-white hover:bg-mist' => ! $w['date']->isSameDay($day),
            ])>
                <span class="block text-[11px] opacity-80">{{ $w['date']->translatedFormat('D') }}</span>
                <span class="block text-lg font-semibold">{{ $w['date']->day }}</span>
                <span @class(['block text-[11px]', 'opacity-80' => $w['date']->isSameDay($day), 'text-muted' => ! $w['date']->isSameDay($day)])>{{ $w['count'] ? $w['count'].' janji' : '—' }}</span>
            </button>
        @endforeach
    </div>
    @endif

    {{-- Daftar janji hari terpilih --}}
    <section class="card mt-6 overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5">
            <h2 class="text-lg font-semibold">{{ $day->isToday() ? 'Hari ini, ' : '' }}{{ $day->translatedFormat('l, j F Y') }}</h2>
            <span class="pill bg-leaf-100 text-brand-600">{{ $appointments->where('status', Appointment::SCHEDULED)->count() }} terjadwal</span>
        </div>
        <x-error for="checkin" />

        <ul class="mt-4 divide-y divide-line">
            @forelse ($appointments as $a)
                <li wire:key="a{{ $a->id }}" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <span class="w-14 shrink-0 text-center text-sm font-semibold">{{ $a->time ? Format::jam($a->time) : '—' }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">
                            {{ $a->patient->name }}
                            @if ($a->patient->hasAllergy())<span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink">Alergi</span>@endif
                            @if ($a->source === 'kontrol')<span class="ml-1 rounded bg-indigo-soft px-1.5 py-0.5 text-[10px] font-semibold text-indigo-ink">Kontrol dokter</span>@endif
                        </p>
                        <p class="mt-1 text-[12px] text-muted">
                            {{ $a->patient->rmCode() }} · {{ $a->purpose ?: 'Pemeriksaan' }} · {{ $a->doctor?->name ?? 'Dokter bertugas' }}
                            @if ($context->isAll()) · {{ $a->branch->shortName() }} @endif
                        </p>
                        @if ($a->note)<p class="mt-1 text-[12px] text-muted italic">{{ $a->note }}</p>@endif
                        @if (in_array($a->id, $doctorAway, true))
                            <p class="mt-1.5 inline-flex rounded bg-rose-soft px-2 py-0.5 text-[11px] font-semibold text-rose-ink">{{ $a->doctor->name }} tidak bertugas di cabang ini hari itu — kabari pasien / ganti dokter</p>
                        @endif
                    </div>
                    <span class="badge min-w-[100px] {{ $a->statusClass() }}">{{ $a->statusLabel() }}{{ $a->visit ? ' · No. '.$a->visit->queueLabel() : '' }}</span>

                    @if ($canWrite && $a->status === Appointment::SCHEDULED)
                        <div class="flex flex-wrap gap-2">
                            @if ($a->date->isToday())
                                <button type="button" wire:click="checkIn({{ $a->id }})" class="btn-primary min-h-10 px-4 text-[13px]">Datang → antrean</button>
                            @endif
                            @if ($wa = $a->whatsappUrl())
                                {{-- Konfirmasi via WhatsApp: membuka WA dengan pesan terisi, admin tinggal kirim --}}
                                <a href="{{ $wa }}" target="_blank" rel="noopener" class="btn-ghost min-h-10 px-3 text-[13px] text-brand-600" title="Kirim pengingat via WhatsApp">Kirim WA</a>
                            @endif
                            <button type="button" wire:click="edit({{ $a->id }})" class="btn-ghost min-h-10 px-3 text-[13px]">Ubah</button>
                            <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                                <button type="button" @click="open = !open" class="btn-ghost min-h-10 px-3 text-[13px]" aria-label="Lainnya">⋯</button>
                                <div x-cloak x-show="open" class="absolute right-0 z-20 mt-1 w-44 rounded-xl border border-line bg-white p-1 shadow-lg">
                                    @unless ($a->date->isFuture())
                                        <button type="button" wire:click="setStatus({{ $a->id }}, 'tidak_datang')" class="flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm hover:bg-mist">Tidak datang</button>
                                    @endunless
                                    <button type="button" wire:click="setStatus({{ $a->id }}, 'batal')" wire:confirm="Batalkan janji ini?" class="flex min-h-10 w-full items-center rounded-lg px-3 text-left text-sm text-rose-ink hover:bg-rose-soft">Batalkan</button>
                                </div>
                            </div>
                        </div>
                    @elseif ($canWrite && in_array($a->status, [Appointment::CANCELLED, Appointment::NO_SHOW], true) && ! $a->date->isPast() || ($canWrite && $a->date->isToday() && in_array($a->status, [Appointment::CANCELLED, Appointment::NO_SHOW], true)))
                        <button type="button" wire:click="setStatus({{ $a->id }}, 'terjadwal')" class="btn-ghost min-h-10 px-3 text-[13px]">Pulihkan</button>
                    @endif
                </li>
            @empty
                <li class="px-5 py-12 text-center text-sm text-muted">Tidak ada janji pada hari ini.</li>
            @endforelse
        </ul>
    </section>
    <p class="mt-3 text-xs text-muted">Janji "Kontrol dokter" dibuat otomatis dari rencana evaluasi di rekam medis (mis. "Evaluasi 2 minggu"). Hubungi pasien sehari sebelumnya untuk konfirmasi.</p>

    {{-- Form janji --}}
    @if ($showForm)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <form wire:submit="save" class="card max-h-[92dvh] w-full max-w-lg overflow-y-auto p-6">
                <h3 class="text-lg font-semibold">{{ $editingId ? 'Ubah janji temu' : 'Buat janji temu' }}</h3>
                <p class="mt-1 text-xs text-muted">{{ $context->label() }}</p>

                <div class="mt-5 space-y-4">
                    <div>
                        <label class="label">Pasien</label>
                        @if ($selectedPatient)
                            <div class="flex items-center justify-between rounded-lg bg-leaf-100 px-4 py-3 text-sm">
                                <span><b>{{ $selectedPatient->name }}</b> · {{ $selectedPatient->rmCode() }}</span>
                                @unless ($editingId)
                                    <button type="button" wire:click="$set('form.patient_id', null)" class="text-xs text-brand-600 hover:underline">Ganti</button>
                                @endunless
                            </div>
                        @else
                            <input type="search" wire:model.live.debounce.300ms="patientSearch" placeholder="Cari nama, HP, atau no. RM…" class="input">
                            @if ($patientResults->isNotEmpty())
                                <ul class="mt-1 rounded-xl border border-line bg-white p-1">
                                    @foreach ($patientResults as $p)
                                        <li><button type="button" wire:click="pickPatient({{ $p->id }})" class="flex min-h-10 w-full items-center justify-between rounded-lg px-3 text-left text-sm hover:bg-mist"><span>{{ $p->name }}</span><span class="text-xs text-muted">{{ $p->rmCode() }}</span></button></li>
                                    @endforeach
                                </ul>
                            @elseif (mb_strlen(trim($patientSearch)) >= 2)
                                <p class="mt-1 text-xs text-muted">Pasien tidak ditemukan. Daftarkan dulu di menu Data pasien.</p>
                            @endif
                        @endif
                        <x-error for="form.patient_id" />
                    </div>

                    @unless ($editingId)<x-branch-pick :context="$context" />@endunless

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Tanggal</label>
                            <input type="date" wire:model.live="form.date" min="{{ today()->toDateString() }}" class="input">
                            <x-error for="form.date" />
                        </div>
                        <div>
                            <label class="label">Jam (opsional)</label>
                            <input type="time" wire:model="form.time" class="input">
                            <x-error for="form.time" />
                        </div>
                    </div>

                    <div>
                        <label class="label">Dokter (opsional)</label>
                        <select wire:model.live="form.doctor_id" class="input">
                            <option value="">Dokter yang bertugas</option>
                            @foreach ($doctors as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}{{ in_array($d->id, $onDutyIds, true) ? ' · bertugas' : '' }}</option>
                            @endforeach
                        </select>
                        @if (($form['doctor_id'] ?? '') && ! in_array((int) $form['doctor_id'], $onDutyIds, true))
                            <p class="mt-1 text-xs text-orange-ink">Dokter ini tidak terjadwal di cabang ini pada tanggal tersebut. Periksa Jadwal dokter.</p>
                        @endif
                    </div>

                    {{-- Ketersediaan dokter di tanggal & cabang terpilih: bantu admin menjawab pasien via WA --}}
                    <div class="rounded-xl bg-canvas p-3">
                        <p class="text-xs font-semibold text-muted uppercase">Dokter bertugas · {{ \Carbon\Carbon::parse($form['date'] ?: today())->translatedFormat('D, j M') }}</p>
                        @if ($context->isAll() && ! $editingId && ! $branchPick)
                            <p class="mt-2 text-xs text-muted">Pilih cabang dulu untuk melihat jadwal dokter.</p>
                        @else
                            <ul class="mt-2 space-y-2 text-[13px]">
                                @forelse ($dayPlan as $plan)
                                    <li>
                                        <button type="button" wire:click="$set('form.doctor_id', '{{ $plan['doctor']->id }}')" class="font-semibold text-brand-600 hover:underline">{{ $plan['doctor']->name }}</button>
                                        <span class="text-muted">· {{ Format::jam($plan['schedule']['starts_at']) }}–{{ Format::jam($plan['schedule']['ends_at']) }}{{ $plan['schedule']['moved'] ? ' (pengganti/pindah)' : '' }}</span>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @forelse ($plan['booked'] as $b)
                                                <span class="rounded bg-white px-1.5 py-0.5 text-[11px] text-muted ring-1 ring-line" title="{{ $b->patient->name }}">{{ $b->time ? Format::jam($b->time) : 'tanpa jam' }} terisi</span>
                                            @empty
                                                <span class="text-[11px] text-brand-600">Belum ada janji — bebas</span>
                                            @endforelse
                                        </div>
                                    </li>
                                @empty
                                    <li class="text-xs text-orange-ink">Tidak ada dokter terjadwal di cabang ini pada tanggal tersebut.</li>
                                @endforelse
                            </ul>
                        @endif
                    </div>

                    <div>
                        <label class="label">Keperluan</label>
                        <input type="text" wire:model="form.purpose" maxlength="255" class="input" placeholder="Mis. kontrol behel, pasang mahkota, scaling">
                    </div>
                    <div>
                        <label class="label">Catatan</label>
                        <input type="text" wire:model="form.note" maxlength="255" class="input" placeholder="Opsional">
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="submit" class="btn-primary flex-1">Simpan</button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-ghost">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
