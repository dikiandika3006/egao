@php use App\Support\Format; @endphp
<div class="w-full">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canEdit)
            @if ($team === 'asisten')
                <button type="button" wire:click="newAssistantRoutine" class="btn-primary">+ &nbsp;Jadwal asisten</button>
            @else
                <button type="button" wire:click="newRoutine" class="btn-primary">+ &nbsp;Jadwal rutin</button>
            @endif
        @endif
    </x-page-header>

    {{-- Tab tim --}}
    <div class="mt-6 inline-flex rounded-xl bg-mist p-1 text-sm" role="tablist">
        @foreach (['dokter' => 'Dokter', 'asisten' => 'Asisten'] as $val => $label)
            <button type="button" role="tab" wire:click="$set('team', '{{ $val }}')" aria-selected="{{ $team === $val ? 'true' : 'false' }}"
                @class(['rounded-lg px-4 py-1.5 font-medium', 'bg-white text-brand-700 shadow-sm' => $team === $val, 'text-muted' => $team !== $val])>{{ $label }}</button>
        @endforeach
    </div>

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

    @if ($team === 'dokter')
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
                                @php $dk = $row['doctor']->id; $dt = $cell['date']->toDateString(); $canDrag = $canEdit && ! $cell['date']->lt(today()); @endphp
                                <button type="button"
                                        @if ($canEdit) wire:click="openDay({{ $dk }}, '{{ $dt }}')" @else disabled @endif
                                        @if ($canDrag)
                                            x-data="{ over: false }" draggable="true"
                                            x-on:dragstart="window.__swapFrom = { d: {{ $dk }}, dt: '{{ $dt }}' }; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '{{ $dk }}|{{ $dt }}')"
                                            x-on:dragend="window.__swapFrom = null"
                                            x-on:dragover="if (window.__swapFrom && window.__swapFrom.dt === '{{ $dt }}' && window.__swapFrom.d !== {{ $dk }}) { $event.preventDefault(); over = true }"
                                            x-on:dragleave="over = false"
                                            x-on:drop.prevent="over = false; const s = window.__swapFrom; window.__swapFrom = null; if (s && s.dt === '{{ $dt }}' && s.d !== {{ $dk }}) $wire.prepareSwap(s.d, {{ $dk }}, s.dt)"
                                            x-bind:class="over && 'ring-2 ring-brand-600'"
                                        @endif
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
                                    @if ($cell['present'])
                                        <span class="mt-1 rounded bg-white/70 px-1 text-[10px] font-semibold text-brand-700" title="Hadir (dasar uang duduk)">✓ S{{ implode('+S', $cell['present']) }}</span>
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
        <p class="mt-3 text-xs text-muted">Klik sel untuk memindahkan dokter sementara atau menandai libur pada tanggal tersebut. Seret sel ke dokter lain pada tanggal yang sama untuk <b>tukar jadwal</b> hari itu. Jadwal rutin tidak berubah.</p>
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
    @else
    {{-- Kisi mingguan asisten --}}
    <section class="card mt-4 overflow-x-auto">
        <table class="w-full min-w-[860px] text-left text-sm">
            <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                <tr>
                    <th class="w-[190px] py-3.5 pl-5">Asisten</th>
                    @foreach ($days as $day)
                        <th @class(['py-3.5 text-center', 'text-brand-600' => $day->isToday()])>
                            {{ $dayNames[$day->dayOfWeekIso] }}<br><span class="text-[11px] normal-case">{{ $day->translatedFormat('j M') }}</span>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse ($assistantGrid as $row)
                    <tr wire:key="a-{{ $row['employee']->id }}" class="border-b border-line">
                        <td class="py-3 pl-5">
                            <p class="font-semibold">{{ $row['employee']->name }}</p>
                            <p class="mt-0.5 text-[11px] text-muted">Utama: {{ $row['employee']->primaryBranch?->shortName() ?? '—' }}</p>
                        </td>
                        @foreach ($row['cells'] as $cell)
                            <td @class(['p-1.5 text-center align-top', 'bg-leaf-50/60' => $cell['date']->isToday()])>
                                <button type="button"
                                    @if ($isOwner) wire:click="openAssistantDay({{ $row['employee']->id }}, '{{ $cell['date']->toDateString() }}')" @else disabled @endif
                                    @class(['flex min-h-14 w-full flex-col items-center justify-center rounded-lg px-1 py-2 text-xs transition',
                                        $cell['branch']?->badgeClass() ?? 'bg-mist text-muted', 'hover:ring-2 hover:ring-leaf-500' => $isOwner])>
                                    @if ($cell['sessions']->isNotEmpty())
                                        <span class="font-semibold">{{ $cell['branch']?->shortName() ?? '—' }}</span>
                                        @foreach ($cell['sessions'] as $s)
                                            <span class="mt-0.5 text-[10px]">{{ Format::jam($s->starts_at) }}–{{ Format::jam($s->ends_at) }}</span>
                                        @endforeach
                                    @else
                                        <span>—</span>
                                    @endif
                                    @if ($cell['present'])
                                        <span class="mt-1 rounded bg-white/70 px-1 text-[10px] font-semibold text-brand-700" title="Hadir (dasar upah shift)">✓ {{ collect($cell['present'])->map(fn ($s) => $s === 1 ? 'Pagi' : 'Sore')->implode('+') }}</span>
                                    @endif
                                </button>
                            </td>
                        @endforeach
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-12 text-center text-muted">Belum ada asisten aktif (jabatan "Asisten dokter" di Gaji → Karyawan).</td></tr>
                @endforelse
            </tbody>
        </table>
    </section>
    @if ($isOwner)
        <p class="mt-3 text-xs text-muted">Klik sel untuk mencentang hadir shift pagi/sore (dasar upah shift asisten). Asisten juga bisa absen sendiri dari menu Absensi.</p>
    @endif

    <section class="card mt-6 p-5 lg:p-6">
        <h2 class="text-lg font-semibold">Jadwal rutin asisten</h2>
        <div class="mt-4 grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            @foreach ($assistantGrid->pluck('employee') as $emp)
                <div wire:key="ra-{{ $emp->id }}" class="rounded-xl bg-mist p-4">
                    <div class="flex items-center justify-between">
                        <p class="font-semibold">{{ $emp->name }}</p>
                        @if ($canEdit)
                            <button type="button" wire:click="newAssistantRoutine({{ $emp->id }})" class="link-arrow">+ Tambah</button>
                        @endif
                    </div>
                    <ul class="mt-3 space-y-1.5 text-[13px]">
                        @forelse ($emp->schedules as $s)
                            <li class="flex items-center justify-between gap-2">
                                <span><b>{{ $dayNames[$s->day_of_week] }}</b> · {{ $s->branch->shortName() }} · {{ Format::jam($s->starts_at) }}–{{ Format::jam($s->ends_at) }}
                                    @if ($s->valid_until)<span class="text-[11px] text-muted">(s.d. {{ $s->valid_until->translatedFormat('j M Y') }})</span>@endif</span>
                                @if ($canEdit)
                                    <button type="button" wire:click="editAssistantRoutine({{ $s->id }})" class="text-xs text-brand-600 hover:underline">Ubah</button>
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
    @endif

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
            @if ($isOwner && ! \Carbon\Carbon::parse($override['date'])->isFuture())
                <div class="mb-4 rounded-xl border border-line p-3">
                    <p class="text-[13px] font-semibold">Tanda hadir (dasar uang duduk)</p>
                    <div class="mt-2 flex gap-2">
                        @foreach ([1, 2] as $sh)
                            <button type="button" wire:click="toggleAttendance({{ $sh }})"
                                @class(['btn flex-1', 'bg-brand-600 text-white' => in_array($sh, $attendance, true), 'bg-mist' => ! in_array($sh, $attendance, true)])>
                                {{ in_array($sh, $attendance, true) ? '✓ ' : '' }}Shift {{ $sh }}
                            </button>
                        @endforeach
                    </div>
                    <x-error for="attendance" />
                    <p class="mt-1 text-[11px] text-muted">Klik untuk menandai/membatalkan. Per shift: kotor tindakan di bawah ambang → uang duduk, selain itu fee %.</p>
                </div>
            @endif
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
                    <x-error for="override.note" />
                </div>
                {{-- Pesan untuk isian yang tidak punya tempat di formulir (dokter, tanggal, dll.) agar Simpan tidak gagal diam-diam. --}}
                @php $lain = collect($errors->getMessages())->except(['override.branch_id', 'override.ends_at', 'override.substitute_id', 'override.note'])->filter(fn ($m, $k) => str_starts_with($k, 'override.'))->flatten(); @endphp
                @if ($lain->isNotEmpty())
                    <p class="sm:col-span-2 rounded-lg bg-rose-soft px-4 py-3 text-sm text-rose-ink">{{ $lain->implode(' ') }}</p>
                @endif
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2">
                    @if ($editingId)
                        <button type="button" wire:click="deleteOverride" class="btn-ghost">Kembalikan ke rutin</button>
                    @else
                        <span></span>
                    @endif
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
            @if ($canEdit && $swapTargets->isNotEmpty() && ! \Carbon\Carbon::parse($override['date'])->lt(today()))
                <div class="mt-5 rounded-xl border border-line p-3" x-data="{ to: '' }">
                    <p class="text-[13px] font-semibold">Tukar jadwal hari ini dengan dokter lain</p>
                    <div class="mt-2 flex gap-2">
                        <select x-model="to" class="input" aria-label="Dokter untuk tukar jadwal">
                            <option value="">Pilih dokter</option>
                            @foreach ($swapTargets as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                        <button type="button" class="btn-soft shrink-0" x-bind:disabled="! to" x-on:click="$wire.prepareSwap({{ (int) $override['doctor_id'] }}, +to, '{{ $override['date'] }}')">Tukar…</button>
                    </div>
                    <p class="mt-1 text-[11px] text-muted">Di layar lebar, bisa juga dengan menyeret sel ke baris dokter lain.</p>
                </div>
            @endif
        </x-modal>
    @endif

    @if ($form === 'swap')
        <x-modal title="Tukar jadwal · {{ \Carbon\Carbon::parse($swap['date'])->translatedFormat('l, j F Y') }}">
            <div class="grid grid-cols-2 gap-3 text-sm">
                <div class="rounded-xl bg-mist p-3"><p class="font-semibold">{{ $swap['from_name'] }}</p><p class="mt-1 text-xs text-muted">Sekarang: {{ $swap['from_slot'] ?? 'tidak ada jadwal' }}</p><p class="mt-1 text-xs">Menjadi: <b>{{ $swap['to_slot'] ?? 'libur' }}</b></p></div>
                <div class="rounded-xl bg-mist p-3"><p class="font-semibold">{{ $swap['to_name'] }}</p><p class="mt-1 text-xs text-muted">Sekarang: {{ $swap['to_slot'] ?? 'tidak ada jadwal' }}</p><p class="mt-1 text-xs">Menjadi: <b>{{ $swap['from_slot'] ?? 'libur' }}</b></p></div>
            </div>
            <label class="mt-4 flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model="swap.move_appointments" class="size-4 accent-brand-600">
                Pindahkan janji temu pasien hari itu ikut ditukar
            </label>
            <x-error for="swap" />
            <p class="mt-2 text-xs text-muted">Hanya tanggal ini; jadwal rutin tidak berubah. Cabang & jam mengikuti jadwal dokter yang ditukar.</p>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                <button type="button" wire:click="confirmSwap" class="btn-primary">Tukar jadwal</button>
            </div>
        </x-modal>
    @endif

    @if ($form === 'assistant-routine')
        <x-modal :title="$editingId ? 'Ubah jadwal asisten' : 'Jadwal asisten baru'">
            <form wire:submit="saveAssistantRoutine" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label">Asisten</label>
                    <select wire:model="routine.employee_id" class="input">
                        <option value="">Pilih asisten</option>
                        @foreach ($assistantGrid->pluck('employee') as $emp)<option value="{{ $emp->id }}">{{ $emp->name }}</option>@endforeach
                    </select>
                    <x-error for="routine.employee_id" />
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
                <div><label class="label">Jam mulai</label><input type="time" wire:model="routine.starts_at" class="input"><x-error for="routine.starts_at" /></div>
                <div><label class="label">Jam selesai</label><input type="time" wire:model="routine.ends_at" class="input"><x-error for="routine.ends_at" /></div>
                <div><label class="label">Berlaku mulai <span class="font-normal text-muted">(opsional)</span></label><input type="date" wire:model="routine.valid_from" class="input"></div>
                <div><label class="label">Berlaku sampai <span class="font-normal text-muted">(opsional)</span></label><input type="date" wire:model="routine.valid_until" class="input"><x-error for="routine.valid_until" /></div>
                <div class="sm:col-span-2"><label class="label">Catatan</label><input type="text" wire:model="routine.note" class="input"></div>
                <p class="text-[11px] text-muted sm:col-span-2">Satu baris = satu sesi. Untuk shift pagi & sore, tambahkan dua baris di hari yang sama. Jam selesai jadi patokan lembur.</p>
                <div class="flex flex-wrap justify-between gap-2 sm:col-span-2">
                    @if ($editingId)
                        <button type="button" wire:click="deleteAssistantRoutine({{ $editingId }})" wire:confirm="Hapus jadwal asisten ini?" class="btn-ghost text-rose-ink">Hapus</button>
                    @else
                        <span></span>
                    @endif
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'assistant-day')
        <x-modal title="{{ $assistantDay['name'] }} · {{ \Carbon\Carbon::parse($assistantDay['date'])->translatedFormat('l, j F Y') }}">
            @if (\Carbon\Carbon::parse($assistantDay['date'])->isFuture())
                <p class="text-sm text-muted">Kehadiran hanya bisa ditandai untuk hari ini atau sebelumnya.</p>
            @else
                <p class="text-[13px] font-semibold">Tanda hadir (dasar upah shift)</p>
                <div class="mt-2 flex gap-2">
                    @foreach ([1 => 'Pagi', 2 => 'Sore'] as $sh => $label)
                        <button type="button" wire:click="toggleAssistantAttendance({{ $sh }})"
                            @class(['btn flex-1', 'bg-brand-600 text-white' => in_array($sh, $attendance, true), 'bg-mist' => ! in_array($sh, $attendance, true)])>
                            {{ in_array($sh, $attendance, true) ? '✓ ' : '' }}Shift {{ $label }}
                        </button>
                    @endforeach
                </div>
                <x-error for="attendance" />
                <p class="mt-2 text-[11px] text-muted">Membatalkan centang juga menghapus jam absen lokasi shift itu. Koreksi jam masuk/pulang & lembur di menu Absensi.</p>
            @endif
        </x-modal>
    @endif
</div>
