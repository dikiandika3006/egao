@php use App\Support\Format; @endphp
<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($isOwner)
            <button type="button" wire:click="newManual" class="btn-soft">+ &nbsp;Absensi manual</button>
        @endif
    </x-page-header>

    {{-- Absen pribadi hari ini --}}
    <section class="card mt-7 p-5 lg:p-6"
        x-data="{
            busy: false, msg: '',
            locate(action, shift) {
                if (! navigator.geolocation) { this.msg = 'Browser tidak mendukung lokasi.'; return }
                if (! window.isSecureContext) { this.msg = 'Lokasi hanya bisa diambil lewat koneksi aman (HTTPS).'; return }
                this.busy = true; this.msg = 'Mengambil lokasi…';
                navigator.geolocation.getCurrentPosition(
                    p => { this.msg = ''; $wire[action](shift, p.coords.latitude, p.coords.longitude, p.coords.accuracy).finally(() => this.busy = false) },
                    e => { this.busy = false; this.msg = 'Gagal mengambil lokasi: ' + e.message + '. Izinkan akses lokasi untuk situs ini.' },
                    { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })
            }
        }">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="eyebrow">Absen saya · {{ $today->translatedFormat('l, j F Y') }}</p>
                <p class="mt-1 text-sm text-muted">
                    @if (! $person)
                        Akun Anda belum terhubung ke data dokter atau karyawan, jadi belum bisa absen.
                    @elseif (! $branch)
                        Pilih cabang tempat Anda bertugas (bukan "Semua cabang") untuk absen.
                    @elseif (! $radiusInfo)
                        Lokasi {{ $branch->name }} belum diatur Owner (Pengaturan › Cabang).
                    @else
                        {{ $person['model']->name }} · {{ $branch->name }} · harus berada dalam {{ $radiusInfo }} m dari klinik.
                    @endif
                </p>
            </div>
        </div>
        <x-error for="absen" />
        <p x-show="msg" x-text="msg" class="mt-2 text-xs text-amber-ink"></p>

        @if ($person && $branch)
            <div class="mt-4 grid gap-3 sm:grid-cols-2">
                @foreach ($shiftLabels as $shift => $label)
                    @php $win = $windows[$shift] ?? null; $rec = $mine[$shift] ?? null; @endphp
                    <div wire:key="me-{{ $shift }}" class="rounded-xl bg-mist p-4">
                        <div class="flex items-center justify-between">
                            <p class="font-semibold">Shift {{ $label }}</p>
                            <span class="text-xs text-muted">{{ $win ? Format::jam($win[0]).'–'.Format::jam($win[1]) : 'Tutup' }}</span>
                        </div>
                        <p class="mt-2 text-xs text-muted">
                            Masuk: <b class="text-brand-700">{{ $rec?->check_in_at?->format('H:i') ?? ($rec ? 'dicentang Owner' : '—') }}</b>
                            · Pulang: <b class="text-brand-700">{{ $rec?->check_out_at?->format('H:i') ?? '—' }}</b>
                            @if (($rec->overtime_minutes ?? 0) > 0) · Lembur {{ $rec->overtime_minutes / 60 }} jam @endif
                        </p>
                        @if ($win && $canEdit)
                            <div class="mt-3 flex gap-2">
                                @if (! $rec?->check_in_at)
                                    <button type="button" class="btn-primary min-h-10 flex-1" x-bind:disabled="busy" x-on:click="locate('checkIn', {{ $shift }})">Absen masuk</button>
                                @elseif (! $rec->check_out_at)
                                    <button type="button" class="btn-primary min-h-10 flex-1" x-bind:disabled="busy" x-on:click="locate('checkOut', {{ $shift }})">Absen pulang</button>
                                @else
                                    <span class="pill bg-leaf-100 text-brand-600">Selesai</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </section>

    {{-- Rekap bulanan (Owner & Admin) --}}
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
                                <span @class(['pill text-[11px]', 'bg-leaf-100 text-brand-600' => $a->viaLocation(), 'bg-mist text-muted' => ! $a->viaLocation()])>{{ $a->viaLocation() ? 'Lokasi'.($a->distance_m !== null ? ' · '.$a->distance_m.' m' : '') : 'Manual' }}</span>
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
