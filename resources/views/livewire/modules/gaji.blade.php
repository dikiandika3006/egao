@php
    $pct = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', '.'), '0'), ',');
@endphp

<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        <button type="button" wire:click="post" wire:confirm="Catat gaji dokter & karyawan {{ $start->translatedFormat('F Y') }} ke pengeluaran? Catatan sebelumnya untuk bulan ini akan diganti." class="btn-primary" @disabled($start->isAfter(now()))>
            {{ $posted ? 'Perbarui di pengeluaran' : 'Catat ke pengeluaran' }}
        </button>
    </x-page-header>

    <div class="mt-7 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-2">
            <button type="button" wire:click="shiftMonth(-1)" class="btn-ghost px-3" aria-label="Bulan sebelumnya">←</button>
            <p class="min-w-[150px] text-center text-sm font-semibold">{{ $start->translatedFormat('F Y') }}</p>
            <button type="button" wire:click="shiftMonth(1)" class="btn-ghost px-3" aria-label="Bulan berikutnya">→</button>
        </div>
        <p class="text-xs text-muted">
            Total gaji bulan ini <b class="text-brand-700">@rupiah($grandTotal)</b> ·
            @if ($posted) Tercatat di pengeluaran: <b class="text-brand-700">@rupiah($posted)</b> @else Belum dicatat ke pengeluaran @endif
            @if ($posted && $posted !== $grandTotal) · <span class="text-orange-ink">ada perubahan, perbarui</span> @endif
        </p>
    </div>
    @if ($ytd['target'] > 0)
        <p class="mt-2 text-xs text-muted">
            Omzet {{ $start->year }} s.d. {{ $start->translatedFormat('F') }}: <b class="text-brand-700">@rupiah($ytd['revenue'])</b> dari target @rupiah($ytd['target']) ·
            @if ($ytd['reached']) <span class="font-semibold text-brand-600">target tercapai, bonus akhir tahun bisa diberikan</span> @else belum tercapai ({{ min(100, (int) floor($ytd['revenue'] * 100 / $ytd['target'])) }}%) @endif
        </p>
    @endif

    {{-- Tab --}}
    <div class="mt-5 flex gap-10 border-b border-line text-sm">
        @foreach (['dokter' => 'Dokter ('.$rows->count().')', 'karyawan' => 'Karyawan ('.$employees->count().')'] as $k => $label)
            <button type="button" wire:click="setTab('{{ $k }}')" @class([
                '-mb-px border-b-2 pb-3 transition',
                'border-leaf-500 font-semibold text-brand-600' => $tab === $k,
                'border-transparent text-muted hover:text-brand-700' => $tab !== $k,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'dokter')
        <div class="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
            @foreach (['gross' => 'Tindakan kotor', 'share' => 'Fee dokter', 'base' => 'Gaji pokok + UD', 'bonus' => 'Bonus/THR − potongan', 'total' => 'Total dibayar'] as $key => $label)
                <div @class(['card p-4 lg:p-5', 'bg-brand-600 text-white' => $key === 'total'])>
                    <p @class(['text-[13px]', 'text-white/85' => $key === 'total', 'text-muted' => $key !== 'total'])>{{ $label }}</p>
                    <p class="mt-3 text-[18px] font-semibold tracking-tight lg:text-[22px]">{{ $totals[$key] < 0 ? '−' : '' }}@rupiah(abs($totals[$key]))</p>
                </div>
            @endforeach
        </div>

        <section class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[820px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3.5 pl-5">Dokter</th>
                        @foreach ($branches as $b)<th class="py-3.5 text-right">{{ $b->shortName() }}</th>@endforeach
                        <th class="py-3.5 text-right">Kotor</th>
                        <th class="py-3.5 text-right">Biaya</th>
                        <th class="py-3.5 text-right">Fee</th>
                        <th class="py-3.5 text-right">Pokok + UD</th>
                        <th class="py-3.5 text-right">Bonus/potongan</th>
                        <th class="py-3.5 text-right">Total</th>
                        <th class="py-3.5 pr-5 text-right">Slip</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($rows as $row)
                        @php $key = 'doctor-'.$row['doctor']->id; $adjNet = $row['bonus'] - $row['deduction']; @endphp
                        <tr wire:key="g-{{ $key }}" class="cursor-pointer border-b border-line hover:bg-mist" wire:click="toggle('{{ $key }}')">
                            <td class="py-3.5 pl-5">
                                <p class="font-semibold">{{ $row['doctor']->name }}</p>
                                <p class="text-[11px] text-muted">{{ $row['count'] }} tindakan · {{ $row['doctor']->share_percent !== null ? $pct($row['doctor']->share_percent).'% khusus' : 'tarif global '.$pct($global).'%' }}</p>
                            </td>
                            @foreach ($branches as $id => $b)
                                <td class="text-right text-[13px]">@rupiah($row['branches'][$id]['gross'] ?? 0)</td>
                            @endforeach
                            <td class="text-right">@rupiah($row['gross'])</td>
                            <td @class(['text-right text-[13px]', 'text-muted' => $row['cost'] + $row['lab'] === 0])>@rupiah($row['cost'] + $row['lab'])</td>
                            <td class="text-right">@rupiah($row['share'])</td>
                            <td class="text-right">@rupiah($row['base'] + $row['ud'])
                                @if ($row['hours'] > 0)<span class="block text-[11px] text-muted">{{ $pct($row['hours']) }} jam</span>@endif
                            </td>
                            <td @class(['text-right', 'text-rose-ink' => $adjNet < 0, 'text-muted' => $adjNet === 0])>{{ $adjNet < 0 ? '−' : ($adjNet > 0 ? '+' : '') }}@rupiah(abs($adjNet))</td>
                            <td class="text-right font-semibold">@rupiah($row['total'])</td>
                            <td class="pr-5 text-right" wire:click.stop>
                                <a href="{{ route('gaji.slip', ['type' => 'doctor', 'id' => $row['doctor']->id, 'bulan' => $start->format('Y-m')]) }}" target="_blank" class="link-arrow">PDF</a>
                            </td>
                        </tr>
                        @if ($expanded === $key)
                            <tr class="border-b border-line bg-mist/60">
                                <td colspan="{{ 9 + $branches->count() }}" class="px-5 py-4">
                                    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
                                        <div>
                                            <p class="text-xs font-semibold text-muted uppercase">Rincian per tindakan</p>
                                            @if ($row['procedures']->isEmpty())
                                                <p class="mt-2 text-sm text-muted">Belum ada tindakan lunas di bulan ini.</p>
                                            @else
                                                <table class="mt-2 w-full text-[13px]">
                                                    <thead class="text-[10px] text-muted uppercase"><tr><th class="py-1.5 text-left">Tindakan</th><th class="text-right">Jumlah</th><th class="text-right">Kotor</th><th class="text-right">Biaya modal</th><th class="text-right">Tarif</th><th class="text-right">Fee</th></tr></thead>
                                                    <tbody>
                                                        @foreach ($row['procedures'] as $p)
                                                            <tr><td class="py-1">{{ $p['name'] }}</td><td class="text-right">{{ $p['count'] }}</td><td class="text-right">@rupiah($p['gross'])</td><td class="text-right">{{ $p['cost'] ? '−' : '' }}@rupiah($p['cost'])</td><td class="text-right">{{ $pct($p['percent']) }}%</td><td class="text-right">@rupiah($p['share'])</td></tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            @endif
                                            @if ($row['lab'] > 0)
                                                <p class="mt-3 text-[13px]">Potongan order lab: biaya lab @rupiah($row['lab']) × fee dokter = <b class="text-rose-ink">−@rupiah($row['lab_deduction'])</b> dari fee.</p>
                                            @endif
                                        </div>
                                        <div class="space-y-4">
                                            @include('livewire.modules.partials.payroll-hours', ['showWork' => true, 'showOvertime' => false, 'hourlyRate' => $rates['doctor_hourly'], 'overtimeRate' => 0])
                                            @include('livewire.modules.partials.payroll-adjustments', ['adjustments' => $row['adjustments']])
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="{{ 9 + $branches->count() }}" class="py-12 text-center text-muted">Belum ada dokter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-muted">Fee dihitung dari tindakan (sebelum diskon) pada tagihan lunas lintas cabang, setelah dipotong biaya modal tindakan dan biaya order lab. Prioritas tarif: khusus dokter × tindakan → tindakan → dokter → global. UD = jam hadir × tarif per jam (Pengaturan → Gaji & bagi hasil). Klik baris untuk rincian, jam kerja, bonus/THR, dan potongan.</p>
    @else
        <div class="mt-5 flex flex-wrap items-end justify-between gap-3">
            <div class="grid flex-1 grid-cols-2 gap-3 sm:max-w-3xl sm:grid-cols-4">
                @foreach (['base' => 'Gaji pokok + UD', 'extra' => 'Fee + lembur', 'bonus' => 'Bonus/THR − potongan', 'total' => 'Total dibayar'] as $key => $label)
                    <div @class(['card p-4', 'bg-brand-600 text-white' => $key === 'total'])>
                        <p @class(['text-[12px]', 'text-white/85' => $key === 'total', 'text-muted' => $key !== 'total'])>{{ $label }}</p>
                        <p class="mt-2 text-[17px] font-semibold tracking-tight lg:text-[20px]">{{ $employeeTotals[$key] < 0 ? '−' : '' }}@rupiah(abs($employeeTotals[$key]))</p>
                    </div>
                @endforeach
            </div>
            <button type="button" wire:click="newEmployee" class="btn-soft">+ Tambah karyawan</button>
        </div>

        <section class="card mt-6 overflow-x-auto">
            <table class="w-full min-w-[880px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr>
                        <th class="py-3.5 pl-5">Karyawan</th>
                        <th class="py-3.5">Cabang</th>
                        <th class="py-3.5 text-right">Pokok + UD</th>
                        <th class="py-3.5 text-right">Fee asisten</th>
                        <th class="py-3.5 text-right">Lembur</th>
                        <th class="py-3.5 text-right">Bonus/potongan</th>
                        <th class="py-3.5 text-right">Total</th>
                        <th class="py-3.5 pr-5 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($employees as $row)
                        @php $e = $row['employee']; $key = 'employee-'.$e->id; $adjNet = $row['bonus'] - $row['deduction']; @endphp
                        <tr wire:key="g-{{ $key }}" class="cursor-pointer border-b border-line hover:bg-mist" wire:click="toggle('{{ $key }}')">
                            <td class="py-3.5 pl-5">
                                <p class="font-semibold">{{ $e->name }} @unless ($e->is_active)<span class="pill ml-1 bg-mist text-muted">Nonaktif</span>@endunless</p>
                                <p class="text-[11px] text-muted">{{ $e->position }} · aturan {{ $row['role'] }}</p>
                            </td>
                            <td class="text-[13px]">{{ $e->primaryBranch?->shortName() ?? '—' }}</td>
                            <td class="text-right">@rupiah($row['base'] + $row['ud'])
                                @if ($row['hours'] > 0)<span class="block text-[11px] text-muted">{{ $pct($row['hours']) }} jam</span>@endif
                            </td>
                            <td @class(['text-right', 'text-muted' => $row['assistant_share'] === 0])>@if ($row['role'] === 'asisten') @rupiah($row['assistant_share']) @else — @endif</td>
                            <td @class(['text-right', 'text-muted' => $row['overtime'] === 0])>@rupiah($row['overtime'])
                                @if ($row['overtime_hours'] > 0)<span class="block text-[11px] text-muted">{{ $pct($row['overtime_hours']) }} jam</span>@endif
                            </td>
                            <td @class(['text-right', 'text-rose-ink' => $adjNet < 0, 'text-muted' => $adjNet === 0])>{{ $adjNet < 0 ? '−' : ($adjNet > 0 ? '+' : '') }}@rupiah(abs($adjNet))</td>
                            <td class="text-right font-semibold">@rupiah($row['total'])</td>
                            <td class="pr-5 text-right whitespace-nowrap" wire:click.stop>
                                <button type="button" wire:click="editEmployee({{ $e->id }})" class="link-arrow mr-3">Ubah</button>
                                <a href="{{ route('gaji.slip', ['type' => 'employee', 'id' => $e->id, 'bulan' => $start->format('Y-m')]) }}" target="_blank" class="link-arrow">PDF</a>
                            </td>
                        </tr>
                        @if ($expanded === $key)
                            <tr class="border-b border-line bg-mist/60">
                                <td colspan="8" class="px-5 py-4">
                                    <div class="grid gap-6 lg:grid-cols-2">
                                        <div class="space-y-3">
                                            @if ($row['role'] === 'asisten')
                                                <p class="text-[13px]">Tindakan lunas yang didampingi: <b>@rupiah($row['assisted'])</b> × {{ $pct($row['assistant_percent']) }}% = <b class="text-brand-600">@rupiah($row['assistant_share'])</b>
                                                    <span class="block text-[11px] text-muted">Asisten pendamping dipilih di Kasir pada tagihan kunjungan.</span></p>
                                            @endif
                                            @include('livewire.modules.partials.payroll-hours', [
                                                'showWork' => $row['role'] === 'asisten', 'showOvertime' => true,
                                                'hourlyRate' => $rates['assistant_hourly'],
                                                'overtimeRate' => $row['role'] === 'asisten' ? $rates['assistant_overtime'] : $rates['admin_overtime'],
                                            ])
                                        </div>
                                        @include('livewire.modules.partials.payroll-adjustments', ['adjustments' => $row['adjustments']])
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="8" class="py-12 text-center text-muted">Belum ada karyawan. Tambahkan admin, perawat, atau staf lain.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
        <p class="mt-3 text-xs text-muted">Jabatan "Asisten dokter" memakai aturan asisten (UD per jam, fee dari tindakan yang didampingi, lembur); jabatan lain memakai aturan admin (gaji pokok + lembur). Fee asisten dibebankan ke cabang tindakan, sisanya ke cabang utama saat dicatat ke pengeluaran.</p>
    @endif

    {{-- Form karyawan --}}
    @if ($showEmployeeForm)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <form wire:submit="saveEmployee" class="card w-full max-w-md p-6">
                <h3 class="text-lg font-semibold">{{ $editingEmployeeId ? 'Ubah karyawan' : 'Tambah karyawan' }}</h3>
                <div class="mt-5 space-y-4">
                    <div>
                        <label class="label">Nama</label>
                        <input type="text" wire:model="employee.name" class="input">
                        @error('employee.name') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Jabatan</label>
                            <select wire:model="employee.position" class="input">
                                @foreach ($positions as $p)<option value="{{ $p }}">{{ $p }}</option>@endforeach
                            </select>
                        </div>
                        <div>
                            <label class="label">Cabang utama</label>
                            <select wire:model="employee.primary_branch_id" class="input">
                                <option value="">Pilih…</option>
                                @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->shortName() }}</option>@endforeach
                            </select>
                            @error('employee.primary_branch_id') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Gaji pokok / bulan</label>
                            <x-money model="employee.base_salary" placeholder="2.500.000" />
                            @error('employee.base_salary') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Tanggal masuk</label>
                            <input type="date" wire:model="employee.joined_at" class="input">
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="employee.is_active" class="size-4 accent-brand-600"> Aktif
                    </label>
                </div>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="submit" class="btn-primary flex-1">Simpan</button>
                    <button type="button" wire:click="$set('showEmployeeForm', false)" class="btn-ghost">Batal</button>
                </div>
            </form>
        </div>
    @endif
</div>
