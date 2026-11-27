@php use App\Support\Format; @endphp
<div class="w-full">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($isOwner)
            <button type="button" wire:click="newManual" class="btn-soft">+ &nbsp;Absensi manual</button>
        @endif
    </x-page-header>

    {{-- Absen pribadi hari ini: satu tombol Check in → Clock out. Cabang dari lokasi, shift dari jam. --}}
    <section class="card mt-7 p-5 lg:p-6"
        x-data="{
            busy: false, msg: '',
            locate(action) {
                const testMode = @js($testMode);
                const send = (lat, lng, acc) => { this.msg = ''; $wire[action](lat, lng, acc).finally(() => this.busy = false) };
                const fail = (text) => { if (testMode) { send(null, null, null) } else { this.busy = false; this.msg = text } };
                this.busy = true;
                if (! navigator.geolocation) return fail('Browser tidak mendukung lokasi.');
                if (! window.isSecureContext) return fail('Lokasi hanya bisa diambil lewat koneksi aman (HTTPS).');
                this.msg = 'Mengambil lokasi…';
                navigator.geolocation.getCurrentPosition(
                    p => send(p.coords.latitude, p.coords.longitude, p.coords.accuracy),
                    e => fail('Gagal mengambil lokasi: ' + e.message + '. Izinkan akses lokasi untuk situs ini.'),
                    { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })
            }
        }">
        @if ($testMode)
            <p class="mb-4 rounded-xl bg-amber-soft px-4 py-2.5 text-xs text-amber-ink">
                <b>Mode uji aktif.</b> Lokasi &amp; jam tidak dicek, cabang = pilihan di header{{ $testBranch ? ' ('.$testBranch->name.')' : '' }}. Matikan di Pengaturan › Absensi sebelum dipakai sungguhan.
            </p>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="eyebrow">Absen saya · {{ $today->translatedFormat('l, j F Y') }}</p>
                @if (! $person)
                    <p class="mt-1 text-sm text-muted">Akun Anda belum terhubung ke data dokter atau karyawan, jadi belum bisa absen.</p>
                @else
                    <p class="mt-1 text-lg font-semibold">{{ $person['model']->name }}</p>
                    <p class="text-sm text-muted">
                        @if ($open)
                            Check in {{ $open->check_in_at->format('H:i') }} · {{ $open->branch?->name }} · Shift {{ $open->shiftLabel() }}
                        @elseif (! $testMode && $locatedBranches->isEmpty())
                            Koordinat cabang belum diatur Owner (Master › Cabang).
                        @else
                            Cabang & shift terdeteksi otomatis. Harus berada dalam {{ $radius }} m dari klinik.
                        @endif
                    </p>
                @endif
            </div>
            @if ($person && $canEdit)
                @if ($open)
                    <button type="button" class="btn-primary min-h-12 px-8 text-base" x-bind:disabled="busy" x-on:click="locate('clockOut')">Clock out</button>
                @else
                    <button type="button" class="btn-primary min-h-12 px-8 text-base" x-bind:disabled="busy" x-on:click="locate('checkIn')">Check in</button>
                @endif
            @endif
        </div>
        <x-error for="absen" />
        <p x-show="msg" x-text="msg" class="mt-2 text-xs text-amber-ink"></p>

        @if ($person && $mine->isNotEmpty())
            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                @foreach ($mine as $rec)
                    <div wire:key="me-{{ $rec->id }}" class="rounded-xl bg-mist px-4 py-3 text-xs text-muted">
                        <p class="text-sm font-semibold text-brand-700">Shift {{ $rec->shiftLabel() }} · {{ $rec->branch?->shortName() }}
                            @if ($rec->viaTest())<span class="pill ml-1 bg-amber-soft text-[10px] text-amber-ink">Uji</span>@endif
                        </p>
                        Masuk <b class="text-brand-700">{{ $rec->check_in_at?->format('H:i') ?? 'dicentang Owner' }}</b>
                        · Pulang <b class="text-brand-700">{{ $rec->check_out_at?->format('H:i') ?? '—' }}</b>
                        @if (($rec->overtime_minutes ?? 0) > 0) · Lembur {{ $rec->overtime_minutes / 60 }} jam @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Rekap bulanan (khusus Owner) --}}
    @if ($isManager)
        <section class="card mt-6 overflow-x-auto">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5 pb-3">
                <h2 class="text-lg font-semibold">Rekap {{ $start->translatedFormat('F Y') }}</h2>
                <input type="month" wire:model.live="month" class="input h-10 w-44" aria-label="Bulan">
            </div>
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3 pl-5">Tanggal</th><th>Nama</th><th>Shift</th><th>Cabang</th>
                        <th>Masuk</th><th>Pulang</th><th class="text-right">Jam kerja</th><th class="text-right">Lembur</th><th>Sumber</th><th class="pr-5"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $r)
                        @php $a = $r['row']; @endphp
                        <tr wire:key="{{ $r['key'] }}" class="border-b border-line">
                            <td class="py-3 pl-5 whitespace-nowrap">{{ $a->date->translatedFormat('D, j M') }}</td>
                            <td><p class="font-semibold">{{ $r['name'] }}</p><p class="text-[11px] text-muted">{{ $r['role'] }}</p></td>
                            <td>{{ $a->shiftLabel() }}</td>
                            <td class="text-[13px]">{{ $a->branch?->shortName() }}</td>
                            <td>{{ $a->check_in_at?->format('H:i') ?? '—' }}</td>
                            <td>{{ $a->check_out_at?->format('H:i') ?? '—' }}</td>
                            <td class="text-right">{{ $a->workedMinutes() ? round($a->workedMinutes() / 60, 1).' j' : '—' }}</td>
                            <td class="text-right">{{ ($a->overtime_minutes ?? 0) > 0 ? round($a->overtime_minutes / 60, 1).' j' : '—' }}</td>
                            <td>
                                <span @class(['pill text-[11px]', 'bg-leaf-100 text-brand-600' => $a->viaLocation(), 'bg-amber-soft text-amber-ink' => $a->viaTest(), 'bg-mist text-muted' => ! $a->viaLocation() && ! $a->viaTest()])>{{ $a->viaTest() ? 'Uji' : ($a->viaLocation() ? 'Lokasi'.($a->distance_m !== null ? ' · '.$a->distance_m.' m' : '') : 'Manual') }}</span>
                                @if ($a->note)<p class="mt-0.5 text-[11px] text-muted">{{ $a->note }}</p>@endif
                            </td>
                            <td class="pr-5 text-right">
                                @if ($isOwner)<button type="button" wire:click="editRow('{{ $r['key'] }}')" class="link-arrow">Koreksi</button>@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="py-12 text-center text-muted">Belum ada absensi di bulan ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-muted">Kehadiran dokter = dasar uang duduk per shift. Kehadiran asisten = upah shift pagi/sore; lembur dihitung per jam penuh setelah akhir jadwal.</p>
    @endif

    @if ($form === 'edit')
        <x-modal title="Koreksi absensi">
            <form wire:submit="saveRow" class="grid gap-4 sm:grid-cols-2">
                <div><label class="label">Jam masuk</label><input type="time" wire:model="edit.check_in" class="input"><x-error for="edit.check_in" /></div>
                <div><label class="label">Jam pulang</label><input type="time" wire:model="edit.check_out" class="input"><x-error for="edit.check_out" /></div>
                @if (str_starts_with((string) $editingKey, 'employee-'))
                    <div><label class="label">Jam lembur</label><input type="text" inputmode="decimal" wire:model="edit.overtime_hours" class="input" placeholder="0"><x-error for="edit.overtime_hours" /></div>
                @endif
                <div class="sm:col-span-2"><label class="label">Catatan koreksi</label><input type="text" wire:model="edit.note" class="input" maxlength="255"><x-error for="edit.note" /></div>
                <div class="flex justify-between gap-2 sm:col-span-2">
                    <button type="button" wire:click="deleteRow" wire:confirm="Hapus absensi ini? Uang duduk/upah shift ikut hilang." class="btn-ghost text-rose-ink">Hapus</button>
                    <button type="submit" class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'manual')
        <x-modal title="Absensi manual">
            <form wire:submit="saveManual" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label">Nama</label>
                    <select wire:model.live="manual.person" class="input">
                        <option value="">— pilih —</option>
                        @foreach ($people as $p)<option value="{{ $p['key'] }}">{{ $p['label'] }}</option>@endforeach
                    </select><x-error for="manual.person" />
                </div>
                <div><label class="label">Tanggal</label><input type="date" wire:model="manual.date" max="{{ now()->toDateString() }}" class="input"><x-error for="manual.date" /></div>
                <div><label class="label">Shift</label>
                    <select wire:model="manual.shift" class="input">@foreach ($shiftLabels as $s => $l)<option value="{{ $s }}">{{ $l }}</option>@endforeach</select><x-error for="manual.shift" />
                </div>
                <div class="sm:col-span-2"><label class="label">Cabang</label>
                    <select wire:model="manual.branch_id" class="input">@foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach</select><x-error for="manual.branch_id" />
                </div>
                <div><label class="label">Jam masuk</label><input type="time" wire:model="manual.check_in" class="input"><x-error for="manual.check_in" /></div>
                <div><label class="label">Jam pulang</label><input type="time" wire:model="manual.check_out" class="input"><x-error for="manual.check_out" /></div>
                @if (str_starts_with((string) ($manual['person'] ?? ''), 'employee-'))
                    <div><label class="label">Jam lembur</label><input type="text" inputmode="decimal" wire:model="manual.overtime_hours" class="input" placeholder="0"><x-error for="manual.overtime_hours" /></div>
                @endif
                <div class="sm:col-span-2"><label class="label">Catatan</label><input type="text" wire:model="manual.note" class="input" maxlength="255" placeholder="mis. HP mati, lupa absen"></div>
                <div class="flex justify-end sm:col-span-2"><button type="submit" class="btn-primary">Simpan</button></div>
            </form>
        </x-modal>
    @endif
</div>
