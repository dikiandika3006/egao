@php
    use App\Enums\VisitStatus;
    use App\Support\Format;
@endphp
<div wire:poll.30s class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canRegister)
            <button type="button" wire:click="register" class="btn-primary">+ &nbsp;Daftarkan kunjungan</button>
        @endif
    </x-page-header>

    <p class="mt-3 text-sm text-muted">{{ now()->translatedFormat('l, j F Y') }} · {{ $context->label() }}</p>
    <x-error for="antrean" />

    <section class="card mt-6 overflow-hidden">
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b border-line px-5 pt-4">
            <div class="-mb-px flex gap-6 overflow-x-auto text-sm">
                @foreach (['menunggu' => 'Menunggu', 'selesai' => 'Selesai tindakan', 'batal' => 'Tidak hadir'] as $key => $label)
                    <button type="button" wire:click="$set('filter', '{{ $key }}')" @class([
                        'shrink-0 border-b-2 pb-3 transition',
                        'border-leaf-500 font-semibold text-brand-600' => $tab === $key,
                        'border-transparent text-muted hover:text-brand-700' => $tab !== $key,
                    ])>{{ $label }} ({{ $counts[$key] }})</button>
                @endforeach
            </div>
            <div class="mb-3 flex flex-1 items-center gap-3">
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau no. RM…" class="input min-h-9 flex-1">
                @if ($isDoctor)
                    <label class="flex shrink-0 items-center gap-2 text-xs text-muted">
                        <input type="checkbox" wire:model.live="mine" class="accent-brand-600"> Pasien saya
                    </label>
                @endif
            </div>
        </div>

        <ul class="divide-y divide-line">
            @forelse ($visits as $v)
                <li wire:key="v-{{ $v->id }}" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <span class="flex h-10 w-12 shrink-0 items-center justify-center rounded-full bg-mist text-sm font-semibold">{{ $v->queueLabel() }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold">
                            {{ $v->patient->name }}
                            @if ($v->patient->hasAllergy())<span class="ml-1 text-[11px] font-medium text-rose-ink">Alergi {{ $v->patient->allergies }}</span>@endif
                        </p>
                        <p class="mt-0.5 text-[12px] text-muted">
                            {{ $v->patient->rmCode() }} · {{ $v->purpose }} · {{ $v->doctor->name }}
                            @if ($context->isAll()) · {{ $v->branch->shortName() }}@endif
                            @if ($v->scheduled_at) · {{ Format::jam($v->scheduled_at) }}@endif
                        </p>
                        @if ($v->complaint)<p class="mt-1 text-[12px] italic text-muted">“{{ $v->complaint }}”</p>@endif
                    </div>
                    <div class="text-right">
                        <span class="badge {{ $v->status->queueBadgeClass() }}">{{ $v->status->queueLabel() }}</span>
                        @if ($hint = $v->status->queueHint())<p class="mt-1 text-[11px] text-muted">{{ $hint }}</p>@endif
                    </div>
                    {{-- key per status: tombol dibuat ulang saat status berubah agar wire:confirm lama tidak menempel --}}
                    <div wire:key="aksi-{{ $v->id }}-{{ $v->status->value }}" class="flex w-full flex-wrap justify-end gap-2 sm:w-auto">
                        @switch($v->status)
                            @case(VisitStatus::Menunggu)
                            @case(VisitStatus::Dipanggil)
                                @if ($canExamine)
                                    <button type="button" wire:click="examine({{ $v->id }})" class="btn-primary min-h-9 px-4">Periksa</button>
                                @endif
                                @if ($canTransfer)
                                    <button type="button" wire:click="openTransfer({{ $v->id }})" class="btn-ghost min-h-9 px-3 text-xs" title="Alihkan ke cabang atau hari lain">Alihkan</button>
                                @endif
                                @if ($canEdit)
                                    <button type="button" wire:click="cancel({{ $v->id }})" wire:confirm="Tandai {{ $v->patient->name }} tidak hadir?" class="btn-ghost min-h-9 px-3 text-xs">Tidak hadir</button>
                                @endif
                                @break
                            @case(VisitStatus::Diperiksa)
                                @if ($canExamine)
                                    <a href="{{ route('rekam-medis.periksa', $v) }}" wire:navigate class="btn-soft min-h-9 px-4">Lanjut periksa</a>
                                @endif
                                @break
                            @case(VisitStatus::MenungguPembayaran)
                                @if (\App\Support\Access::can('kasir', 'lihat'))
                                    <a href="{{ route('kasir', ['kunjungan' => $v->id]) }}" wire:navigate class="btn-soft min-h-9 px-4">Ke kasir</a>
                                @endif
                                @break
                            @case(VisitStatus::Batal)
                                @if ($moved = $transfers->get($v->id))
                                    <span class="text-xs text-muted">Dialihkan ke {{ $moved->branch->shortName() }}, {{ $moved->date->translatedFormat('j M') }}{{ $moved->time ? ' '.Format::jam($moved->time) : '' }}</span>
                                @elseif ($canEdit)
                                    <button type="button" wire:click="backToWaiting({{ $v->id }})" class="btn-ghost min-h-9 px-3 text-xs">Masukkan lagi</button>
                                @endif
                                @break
                            @default
                                <span class="text-xs text-muted">{{ $v->completed_at?->format('H.i') }}</span>
                        @endswitch
                    </div>
                </li>
            @empty
                <li class="py-14 text-center text-sm text-muted">{{ $search ? 'Tidak ada pasien yang cocok.' : 'Belum ada antrean.' }}</li>
            @endforelse
        </ul>
    </section>

    @if ($showForm)
        <x-modal title="Daftarkan kunjungan">
            <form wire:submit="save" class="space-y-4">
                <x-branch-pick :context="$context" />
                <div>
                    <label class="label">Pasien</label>
                    @if ($chosen)
                        <div class="flex items-center justify-between rounded-lg bg-leaf-50 px-4 py-3">
                            <div>
                                <p class="font-semibold">{{ $chosen->name }}</p>
                                <p class="text-xs text-muted">{{ $chosen->rmCode() }} · {{ $chosen->ageLabel(true) }} @if ($chosen->hasAllergy())<span class="text-rose-ink">• Alergi {{ $chosen->allergies }}</span>@endif</p>
                            </div>
                            <button type="button" wire:click="$set('visit.patient_id', null)" class="text-xs text-brand-600 hover:underline">Ganti</button>
                        </div>
                    @else
                        <input type="search" wire:model.live.debounce.250ms="patientSearch" class="input" placeholder="Ketik nama, no. RM, atau telepon…" autofocus>
                        @if ($candidates->isNotEmpty())
                            <ul class="mt-2 divide-y divide-line rounded-lg border border-line">
                                @foreach ($candidates as $c)
                                    <li><button type="button" wire:click="pickPatient({{ $c->id }})" class="w-full px-4 py-2.5 text-left text-sm hover:bg-mist">
                                        <b>{{ $c->name }}</b> <span class="text-xs text-muted">{{ $c->rmCode() }} · {{ $c->ageLabel(true) }} · {{ $c->maskedPhone() }}</span>
                                    </button></li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($patientSearch)) >= 2)
                            <p class="mt-2 text-xs text-muted">Tidak ditemukan. <a href="{{ route('pasien', ['baru' => 1]) }}" wire:navigate class="text-brand-600 underline">Daftarkan pasien baru</a></p>
                        @endif
                    @endif
                    <x-error for="visit.patient_id" />
                </div>
                <div>
                    <label class="label">Dokter</label>
                    <select wire:model="visit.doctor_id" class="input">
                        @foreach ($doctorOptions as $row)
                            <option value="{{ $row['doctor']->id }}">{{ $row['doctor']->name }}@if ($row['schedule']) ({{ $row['schedule']['hours'] }})@endif</option>
                        @endforeach
                    </select>
                    <x-error for="visit.doctor_id" />
                </div>
                <div class="grid gap-4 sm:grid-cols-[1fr_140px]">
                    <div>
                        <label class="label">Keperluan</label>
                        <input type="text" wire:model="visit.purpose" class="input" list="purposes" placeholder="mis. Scaling, Tambal gigi">
                        <datalist id="purposes">
                            @foreach (['Konsultasi', 'Pemeriksaan rutin', 'Scaling', 'Tambal gigi', 'Cabut gigi', 'Kontrol behel'] as $p)<option value="{{ $p }}">@endforeach
                        </datalist>
                        <x-error for="visit.purpose" />
                    </div>
                    <div>
                        <label class="label">Jam</label>
                        <input type="time" wire:model="visit.scheduled_at" class="input">
                    </div>
                </div>
                <div>
                    <label class="label">Keluhan</label>
                    <textarea wire:model="visit.complaint" rows="2" class="input py-2.5"></textarea>
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                    <button class="btn-primary">Ambil nomor antrean</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($transfer)
        <x-modal title="Alihkan pasien" close="closeTransfer">
            <form wire:submit="saveTransfer" class="space-y-4">
                @if ($transferVisit)
                    <p class="rounded-lg bg-leaf-50 px-4 py-3 text-sm">
                        <b>{{ $transferVisit->patient->name }}</b> · No. {{ $transferVisit->queueLabel() }} · {{ $transferVisit->branch->shortName() }}
                    </p>
                @endif
                <p class="text-xs text-muted">Antrean hari ini dibatalkan dan diganti janji temu di cabang/tanggal tujuan. Saat pasien tiba, cabang tujuan menekan "Datang → antrean" di menu Janji temu.</p>
                <div>
                    <label class="label">Cabang tujuan</label>
                    <select wire:model.live="transfer.branch_id" class="input">
                        <option value="">Pilih cabang…</option>
                        @foreach ($transferBranches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <x-error for="transfer.branch_id" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label">Tanggal</label>
                        <input type="date" wire:model.live="transfer.date" min="{{ today()->toDateString() }}" class="input">
                        <x-error for="transfer.date" />
                    </div>
                    <div>
                        <label class="label">Jam (opsional)</label>
                        <input type="time" wire:model.live.debounce.500ms="transfer.time" class="input">
                        <x-error for="transfer.time" />
                    </div>
                </div>
                <div>
                    <label class="label">Dokter (opsional)</label>
                    <select wire:model="transfer.doctor_id" class="input">
                        <option value="">Dokter yang bertugas</option>
                        @foreach ($transferDoctors as $d)
                            <option value="{{ $d->id }}">{{ $d->name }}{{ in_array($d->id, $transferOnDuty, true) ? ' · bertugas' : '' }}</option>
                        @endforeach
                    </select>
                    <x-error for="transfer.doctor_id" />
                </div>
                @if ($transferWarning)
                    <p class="rounded-xl bg-amber-soft px-3 py-2 text-[13px] text-amber-ink">⚠ {{ $transferWarning }}</p>
                @endif
                <div>
                    <label class="label">Alasan</label>
                    <input type="text" wire:model="transfer.reason" maxlength="255" class="input" placeholder="Mis. antrean penuh, dokter tidak praktik">
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach (['Antrean penuh', 'Dokter tidak praktik', 'Pasien minta cabang lain', 'Pasien minta hari lain'] as $r)
                            <button type="button" wire:click="$set('transfer.reason', @js($r))" class="pill bg-mist text-[12px] hover:bg-leaf-100">{{ $r }}</button>
                        @endforeach
                    </div>
                    <x-error for="transfer.reason" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" wire:click="closeTransfer" class="btn-ghost">Batal</button>
                    <button class="btn-primary">Alihkan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
