@php
    use App\Enums\VisitStatus;
    use App\Support\Format;
@endphp

{{-- Urutan ponsel: ringkasan owner tampil tepat di bawah judul ("Klinik dalam genggaman", FR-MOB-02). --}}
<div wire:poll.30s class="mx-auto flex max-w-[1152px] flex-col">
    {{-- Judul (FR-DSH-01) --}}
    <div class="order-0 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-[26px] font-semibold tracking-tight lg:text-[32px]">{{ $greeting }}, {{ auth()->user()->firstName() }}</h1>
            <p class="mt-2 text-sm text-muted lg:text-[15px]">
                {{ now()->translatedFormat('l, j F Y') }}
                @if ($branch)
                    <span class="mx-1.5">•</span> Jam praktik hari ini {{ $branch->hoursLabel(now()) }}
                @else
                    <span class="mx-1.5">•</span> Semua cabang
                @endif
            </p>
        </div>
        <a href="{{ route('pasien', ['baru' => 1]) }}" wire:navigate class="btn-primary hidden min-w-[200px] lg:inline-flex">+ &nbsp;Daftarkan pasien</a>
    </div>

    {{-- KPI (FR-DSH-02) --}}
    <p class="order-2 mt-7 text-[10px] font-medium text-muted uppercase">Hari ini</p>
    <div class="order-2 mt-3 grid grid-cols-2 gap-3 lg:grid-cols-4 lg:gap-4">
        <div class="card bg-leaf-100 p-4 lg:p-5">
            <p class="text-[13px] text-muted">Pasien hari ini</p>
            <p class="mt-3 text-[26px] font-semibold lg:text-[28px]">{{ $kpi['patients'] }}</p>
            <p class="mt-3 text-xs text-muted">{{ $kpi['done'] }} kunjungan selesai</p>
        </div>
        <div class="card p-4 lg:p-5">
            <p class="text-[13px] text-muted">Menunggu</p>
            <p class="mt-3 text-[26px] font-semibold lg:text-[28px]">{{ $kpi['waiting'] }}</p>
            <p class="mt-3 text-xs text-muted">Belum diperiksa</p>
        </div>
        <div class="card p-4 lg:p-5">
            <p class="text-[13px] text-muted">Sedang diperiksa</p>
            <p class="mt-3 text-[26px] font-semibold lg:text-[28px]">{{ $kpi['examining'] }}</p>
            <p class="mt-3 truncate text-xs text-muted">{{ $kpi['examiningDoctor'] }}</p>
        </div>
        <div class="card p-4 lg:p-5">
            <p class="text-[13px] text-muted">Omzet hari ini</p>
            <p class="mt-3 text-[22px] font-semibold tracking-tight lg:text-[28px]">{{ Format::rupiah($kpi['revenue']) }}</p>
            <p class="mt-3 text-xs text-muted">Dari {{ $kpi['paidCount'] }} pembayaran lunas</p>
        </div>
    </div>

    <div class="order-3 mt-6 grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1fr)_328px]">
        {{-- Antrean hari ini (FR-DSH-03) --}}
        <section class="card overflow-hidden">
            <div class="px-5 pt-5 lg:px-[22px]">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold lg:text-xl">Antrean hari ini</h2>
                    <span class="pill min-w-[94px] bg-leaf-100 text-brand-600">{{ $activeCount }} aktif</span>
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-x-16 gap-y-3 border-b border-line">
                    <div class="flex gap-12 text-sm">
                        @foreach (['aktif' => "Aktif ({$activeCount})", 'selesai' => "Selesai ({$doneCount})"] as $key => $label)
                            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                                '-mb-px border-b-2 pb-3 transition',
                                'border-leaf-500 font-semibold text-brand-600' => $tab === $key,
                                'border-transparent text-muted hover:text-brand-700' => $tab !== $key,
                            ])>{{ $label }}</button>
                        @endforeach
                    </div>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari nama atau no. RM..." class="mb-2 w-full min-w-0 flex-1 bg-transparent py-1.5 text-sm placeholder:text-muted focus:outline-none sm:w-auto">
                </div>
            </div>

            {{-- Tabel desktop --}}
            <div class="hidden md:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                        <tr>
                            <th class="w-[68px] py-3.5 pl-[22px]">No.</th>
                            <th class="py-3.5">Pasien</th>
                            @if ($context->isAll())<th class="py-3.5">Cabang</th>@endif
                            <th class="py-3.5">Jadwal</th>
                            <th class="py-3.5">Status</th>
                            <th class="py-3.5 pr-[22px]">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($visits as $visit)
                            <tr wire:key="v-{{ $visit->id }}" class="border-b border-line last:border-0 [&>td]:py-3.5">
                                <td class="pl-[22px] align-top text-muted">{{ $visit->queueLabel() }}</td>
                                <td>
                                    <p class="font-medium">
                                        {{ $visit->patient->name }}
                                        @if ($visit->patient->hasAllergy())
                                            <span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink" title="Alergi: {{ $visit->patient->allergies }}">Alergi</span>
                                        @endif
                                    </p>
                                    <p class="mt-1 text-[11px] text-muted">{{ $visit->patient->rmCode() }} • {{ $visit->purpose }}</p>
                                </td>
                                @if ($context->isAll())<td class="text-[13px]">{{ $visit->branch->shortName() }}</td>@endif
                                <td class="text-[13px]">{{ Format::jam($visit->scheduled_at) }}</td>
                                <td><span class="badge {{ $visit->status->badgeClass() }}">{{ $visit->status->label() }}</span></td>
                                <td class="pr-[22px]">
                                    @include('livewire.partials.queue-action', ['visit' => $visit])
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-[22px] py-12 text-center text-sm text-muted">{{ $search ? 'Tidak ada pasien yang cocok.' : 'Belum ada antrean.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Kartu ponsel (FR-ANT-10) --}}
            <div class="space-y-3 bg-canvas p-3 md:hidden">
                @forelse ($visits as $visit)
                    <div wire:key="m-{{ $visit->id }}" class="card flex gap-4 p-4">
                        <span class="flex h-8 w-12 shrink-0 items-center justify-center rounded-full bg-mist text-[13px] text-muted">{{ $visit->queueLabel() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold">{{ $visit->patient->name }}</p>
                            <p class="mt-1 text-[11px] text-muted">{{ $visit->patient->rmCode() }} • {{ $visit->purpose }}</p>
                            <div class="mt-3 flex items-center justify-between">
                                <span class="text-xs text-muted">{{ Format::jam($visit->scheduled_at) }}@if ($context->isAll()) • {{ $visit->branch->shortName() }}@endif</span>
                                @include('livewire.partials.queue-action', ['visit' => $visit])
                            </div>
                        </div>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-muted">Belum ada antrean.</p>
                @endforelse
            </div>
        </section>

        <div class="space-y-6">
            {{-- Dokter bertugas (FR-DSH-04) --}}
            <section class="card p-5">
                <h2 class="text-lg font-semibold">Dokter bertugas</h2>
                <div class="mt-4 divide-y divide-line">
                    @forelse ($onDuty as $row)
                        <div class="py-4 first:pt-0">
                            <span class="pill bg-leaf-100 text-brand-600">{{ $row['schedule']['moved'] ? 'Pindah sementara' : 'Praktik' }}</span>
                            <p class="mt-4 text-lg font-semibold">{{ $row['doctor']->name }}</p>
                            <p class="mt-1.5 text-sm text-muted">{{ $row['branch']?->shortName() }} • {{ $row['schedule']['hours'] }}</p>
                        </div>
                    @empty
                        <p class="pb-4 text-sm text-muted">Tidak ada dokter yang praktik hari ini.</p>
                    @endforelse
                </div>
                <div class="border-t border-line pt-4">
                    <a href="{{ route('jadwal') }}" wire:navigate class="link-arrow font-medium">Lihat jadwal lintas cabang <span aria-hidden="true">→</span></a>
                </div>
            </section>

            <section class="hidden rounded-2xl bg-leaf-100 p-5 lg:block">
                <h2 class="text-2xl leading-snug font-semibold text-brand-600">Dari pemeriksaan langsung ke kasir.</h2>
                <p class="mt-5 text-sm leading-relaxed text-brand-600">Tindakan dokter otomatis menjadi rincian tagihan. Tidak perlu mengetik ulang.</p>
            </section>
        </div>
    </div>

    {{-- Ringkasan owner (FR-DSH-05/06/07, FR-MOB-02) --}}
    @if ($showOwner && $owner)
        <section class="order-1 mt-6 grid gap-4 lg:order-4 lg:grid-cols-3">
            <div class="flex flex-col justify-between rounded-2xl bg-brand-600 p-5 text-white">
                <div>
                    <p class="text-sm text-white/85">Omzet hari ini</p>
                    <p class="mt-3 text-[30px] font-semibold tracking-tight">{{ Format::rupiah($owner['revenueToday']) }}</p>
                </div>
                <div class="mt-6 space-y-1 text-[13px] text-white/80">
                    <p>Bulan berjalan {{ Format::rupiah($owner['revenueMonth']) }}</p>
                    <p>Pengeluaran {{ Format::rupiah($owner['expenseToday']) }} • Kas {{ Format::rupiah($owner['cashToday']) }}</p>
                </div>
            </div>

            <div class="card p-5">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Omzet 7 hari</h2>
                    <span class="text-[11px] text-muted">Rp juta</span>
                </div>
                <div class="mt-4 flex h-32 items-end justify-between gap-2">
                    @foreach ($owner['days'] as $day)
                        <div class="flex flex-1 flex-col items-center gap-2" title="{{ $day['date']->translatedFormat('l, j M') }}: {{ Format::rupiah($day['total']) }}">
                            @if ($day['today'])
                                <span class="text-[11px] font-semibold">{{ Format::juta($day['total']) }}</span>
                            @endif
                            <div @class(['w-full max-w-6 rounded-md', 'bg-brand-600' => $day['today'], 'bg-leaf-200' => ! $day['today']])
                                 style="height: {{ max(4, round($day['total'] / $owner['max'] * 84)) }}px"></div>
                            <span class="text-[10px] text-muted">{{ $day['date']->day }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card p-5">
                <h2 class="font-semibold">Per cabang</h2>
                <div class="mt-3 divide-y divide-line text-sm">
                    @foreach ($owner['perBranch'] as $row)
                        <div class="flex items-center justify-between py-3">
                            <span>{{ $row['branch']->shortName() }}</span>
                            <span class="font-semibold">{{ Format::rupiah($row['today']) }}</span>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between py-3 text-muted">
                        <span>Total gabungan</span>
                        <span class="font-semibold text-brand-700">{{ Format::rupiah($owner['revenueToday']) }}</span>
                    </div>
                </div>
                <a href="{{ route('laporan') }}" wire:navigate class="link-arrow mt-1">Lihat keuangan & rekap bagi hasil <span aria-hidden="true">→</span></a>
            </div>
        </section>
    @endif

    <div class="order-5 mt-6 rounded-xl bg-mist px-5 py-4">
        <p class="text-sm font-medium">Satu data pasien untuk dua cabang</p>
        <p class="mt-1.5 text-xs text-muted">
            Riwayat Batu dan Malang tetap tersambung.
            {{ $branch ? "Antrean ini hanya menampilkan {$branch->name}." : 'Antrean menampilkan semua cabang.' }}
        </p>
    </div>
</div>
