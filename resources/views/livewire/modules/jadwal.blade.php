@php use App\Support\Format; @endphp
<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canEdit)
            <button type="button" wire:click="newRoutine" class="btn-primary">+ &nbsp;Jadwal rutin</button>
        @endif
    </x-page-header>

    {{-- Navigasi minggu --}}
    <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="shiftWeek(-1)" class="btn-ghost px-3" aria-label="Minggu sebelumnya">←</button>
            <p class="min-w-[220px] text-center text-sm font-semibold">
                {{ $days->first()->translatedFormat('j M') }} – {{ $days->last()->translatedFormat('j M Y') }}
            </p>
            <button type="button" wire:click="shiftWeek(1)" class="btn-ghost px-3" aria-label="Minggu berikutnya">→</button>
            <button type="button" wire:click="thisWeek" class="btn-soft min-h-9 px-3 text-xs">Minggu ini</button>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs text-muted">
            @foreach ($branches as $b)
                <span class="pill {{ $b->badgeClass() }}">{{ $b->shortName() }}</span>
            @endforeach
            <span class="pill bg-orange-soft text-orange-ink">Pindah sementara</span>
            <span class="pill bg-rose-soft text-rose-ink">Libur</span>
        </div>
    </div>

    {{-- Kisi mingguan (FR-JDW-01/02) --}}
    <section class="card mt-4 overflow-x-auto">
        <table class="w-full min-w-[860px] text-left text-sm">
            <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                <tr>
                    <th class="w-[190px] py-3.5 pl-5">Dokter</th>
                    @foreach ($days as $i => $day)
                        <th @class(['py-3.5 text-center', 'text-brand-600' => $day->isToday()])>
                            {{ $dayNames[$day->dayOfWeekIso] }}<br>
                            <span class="text-[11px] normal-case">{{ $day->translatedFormat('j M') }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            {{-- Satu <tbody> per blok cabang: Batu di atas, dokter dua cabang di perbatasan, lalu Malang. --}}
            @forelse ($groups as $g => $group)
            <tbody wire:key="g-{{ $g }}-{{ $group['branch']?->id ?? $group['label'] }}">
                <tr class="border-b border-line bg-canvas/50">
                    <td colspan="8" class="py-2 pl-5">
                        <span @class(['pill text-[11px] font-semibold', $group['branch']?->badgeClass() ?? 'bg-mist text-brand-600'])>{{ $group['label'] }}</span>
                        <span class="ml-2 text-[11px] text-muted">{{ $group['rows']->count() }} dokter</span>
                    </td>
                </tr>
                @foreach ($group['rows'] as $row)
                    <tr wire:key="d-{{ $row['doctor']->id }}" class="border-b border-line">
                        <td class="py-3 pl-5">
                            <p class="font-semibold">{{ $row['doctor']->name }}</p>
                            <p class="mt-0.5 text-[11px] text-muted">Utama: {{ $row['doctor']->primaryBranch?->shortName() ?? '—' }}</p>
                        </td>
                        @foreach ($row['cells'] as $cell)
                            <td @class(['p-1.5 text-center align-top', 'bg-leaf-50/60' => $cell['date']->isToday()])>
                                @php
                                    $classes = match (true) {
                                        $cell['off'] => 'bg-rose-soft text-rose-ink',
                                        (bool) $cell['schedule'] && $cell['schedule']['moved'] => 'bg-orange-soft text-orange-ink',
                                        (bool) $cell['branch'] => $cell['branch']->badgeClass(),
                                        default => 'bg-mist text-muted',
                                    };
                                    $dim = $context->id() && $cell['branch'] && $cell['branch']->id !== $context->id();
                                @endphp
                                <button type="button"
                                        @if ($canEdit) wire:click="openDay({{ $row['doctor']->id }}, '{{ $cell['date']->toDateString() }}')" @else disabled @endif
                                        title="{{ $cell['note'] }}"
                                        @class(['flex min-h-14 w-full flex-col items-center justify-center rounded-lg px-1 py-2 text-xs transition', $classes, 'opacity-50' => $dim, 'hover:ring-2 hover:ring-leaf-500' => $canEdit])>
                                    @if ($cell['off'])
                                        <span class="font-semibold">Libur</span>
                                    @elseif ($cell['schedule'])
                                        <span class="font-semibold">{{ $cell['branch']?->shortName() ?? '—' }}</span>
                                        @foreach ($cell['schedule']['sessions'] as $sesi)
                                            <span class="mt-0.5 text-[10px]">{{ Format::jam($sesi['starts_at']) }}–{{ Format::jam($sesi['ends_at']) }}</span>
                                        @endforeach
                                    @else
                                        <span>—</span>
                                    @endif
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
            @empty
            <tbody>
                <tr><td colspan="8" class="py-12 text-center text-muted">Belum ada dokter aktif.</td></tr>
            </tbody>
            @endforelse
            <tfoot class="bg-canvas/70 text-[11px] text-muted">
                <tr>
                    <td class="py-3 pl-5">Dokter per cabang</td>
                    @foreach ($perDay as $counts)
                        <td class="py-3 text-center">
                            @foreach ($branches as $id => $b)
                                <span class="block">{{ $b->shortName() }}: <b class="text-brand-700">{{ $counts[$id] }}</b></span>
                            @endforeach
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        </table>
    </section>
    @if ($canEdit)
        <p class="mt-3 text-xs text-muted">Klik sel untuk memindahkan dokter sementara atau menandai libur pada tanggal tersebut. Jadwal rutin tidak berubah.</p>
    @endif

    {{-- Daftar jadwal rutin (FR-JDW-06) --}}
    <section class="card mt-6 p-5 lg:p-6">
        <h2 class="text-lg font-semibold">Jadwal rutin mingguan</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach (collect($groups)->flatMap(fn ($g) => $g['rows'])->pluck('doctor')->unique('id') as $doctor)
                <div wire:key="r-{{ $doctor->id }}" class="rounded-xl bg-mist p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-semibold">{{ $doctor->name }}</p>
                        @if ($canEdit)
                            <button type="button" wire:click="newRoutine({{ $doctor->id }})" class="link-arrow">+ Tambah</button>
                        @endif
                    </div>
                    <ul class="mt-3 space-y-1.5 text-[13px]">
                        @forelse ($doctor->schedules as $s)
                            <li class="flex items-center justify-between gap-2">
                                <span>
                                    <b>{{ $dayNames[$s->day_of_week] }}</b> · {{ $s->branch->shortName() }} · {{ Format::jam($s->starts_at) }}–{{ Format::jam($s->ends_at) }}
                                    @if ($s->valid_until)<span class="text-[11px] text-muted">(s.d. {{ $s->valid_until->translatedFormat('j M Y') }})</span>@endif
                                </span>
                                @if ($canEdit)
                                    <button type="button" wire:click="editRoutine({{ $s->id }})" class="text-xs text-brand-600 hover:underline">Ubah</button>
                                @endif
                            </li>
                        @empty
                            <li class="text-muted">Belum ada jadwal rutin.</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </div>
    </section>

    @if ($form === 'routine')
        <x-modal :title="$editingId ? 'Ubah jadwal rutin' : 'Jadwal rutin baru'">
            <form wire:submit="saveRoutine" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label">Dokter</label>
                    <select wire:model="routine.doctor_id" class="input">
                        <option value="">Pilih dokter</option>
                        @foreach ($doctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                    </select>
                    <x-error for="routine.doctor_id" />
                </div>
                <div>
                    <label class="label">Hari</label>
                    <select wire:model="routine.day_of_week" class="input">
                        @foreach ($dayNames as $n => $name)<option value="{{ $n }}">{{ $name }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Cabang</label>
                    <select wire:model="routine.branch_id" class="input">
                        @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->shortName() }}</option>@endforeach
                    </select>
                    <x-error for="routine.branch_id" />
                </div>
                <div>
                    <label class="label">Jam mulai</label>
                    <input type="time" wire:model="routine.starts_at" class="input">
                    <x-error for="routine.starts_at" />
                </div>
                <div>
                    <label class="label">Jam selesai</label>
                    <input type="time" wire:model="routine.ends_at" class="input">
                    <x-error for="routine.ends_at" />
                </div>
                <div>
                    <label class="label">Berlaku mulai <span class="font-normal text-muted">(opsional)</span></label>
                    <input type="date" wire:model="routine.valid_from" class="input">
                </div>
                <div>
                    <label class="label">Berlaku sampai <span class="font-normal text-muted">(opsional)</span></label>
                    <input type="date" wire:model="routine.valid_until" class="input">
                    <x-error for="routine.valid_until" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Catatan</label>
                    <input type="text" wire:model="routine.note" class="input">
                </div>
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2">
                    @if ($editingId)
                        <button type="button" wire:click="deleteRoutine({{ $editingId }})" wire:confirm="Hapus jadwal rutin ini?" class="btn-ghost text-rose-ink">Hapus</button>
                    @else
                        <span></span>
                    @endif
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'override')
        @php $doc = $doctors->firstWhere('id', $override['doctor_id']); @endphp
        <x-modal title="{{ $doc?->name }} · {{ \Carbon\Carbon::parse($override['date'])->translatedFormat('l, j F Y') }}">
            <form wire:submit="saveOverride" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2 flex gap-2">
                    @foreach (['move' => 'Praktik / pindah cabang', 'off' => 'Libur / izin'] as $val => $label)
                        <label @class(['btn flex-1 cursor-pointer', 'bg-brand-600 text-white' => $override['type'] === $val, 'bg-mist' => $override['type'] !== $val])>
                            <input type="radio" wire:model.live="override.type" value="{{ $val }}" class="sr-only"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                @if ($override['type'] === 'move')
                    <div class="sm:col-span-2">
                        <label class="label">Cabang</label>
                        <select wire:model="override.branch_id" class="input">
                            @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->shortName() }}</option>@endforeach
                        </select>
                        <x-error for="override.branch_id" />
                    </div>
                    <div>
                        <label class="label">Jam mulai <span class="font-normal text-muted">(kosong = ikut rutin)</span></label>
                        <input type="time" wire:model="override.starts_at" class="input">
                    </div>
                    <div>
                        <label class="label">Jam selesai</label>
                        <input type="time" wire:model="override.ends_at" class="input">
                        <x-error for="override.ends_at" />
                    </div>
                @else
                    {{-- Dokter jaga izin → langsung tunjuk pengganti di cabang & jam yang sama --}}
                    <div class="sm:col-span-2 rounded-xl bg-mist p-3">
                        <label class="label">Digantikan oleh <span class="font-normal text-muted">(opsional)</span></label>
                        <select wire:model.live="override.substitute_id" class="input bg-white">
                            <option value="">— Tidak ada pengganti —</option>
                            @foreach ($doctors->where('id', '!=', $override['doctor_id']) as $d)
                                <option value="{{ $d->id }}">{{ $d->name }}</option>
                            @endforeach
                        </select>
                        <x-error for="override.substitute_id" />
                        @if ($override['substitute_id'] ?? null)
                            <label class="mt-2 flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="override.move_appointments" class="size-4 accent-brand-600">
                                Pindahkan janji temu pasien hari itu ke dokter pengganti
                            </label>
                            <p class="mt-1 text-xs text-muted">Pengganti dijadwalkan di cabang & jam praktik dokter yang izin.</p>
                        @else
                            <p class="mt-1 text-xs text-muted">Tanpa pengganti, janji temu dokter ini akan ditandai merah di menu Janji temu agar pasien dikabari.</p>
                        @endif
                    </div>
                @endif
                <div class="sm:col-span-2">
                    <label class="label">Alasan / catatan</label>
                    <input type="text" wire:model="override.note" class="input" placeholder="mis. Malang penuh, cuti">
                </div>
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2">
                    @if ($editingId)
                        <button type="button" wire:click="deleteOverride" class="btn-ghost">Kembalikan ke rutin</button>
                    @else
                        <span></span>
                    @endif
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
