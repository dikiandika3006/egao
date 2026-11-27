@php use App\Enums\VisitStatus; use App\Support\Format; @endphp

<div wire:poll.30s class="w-full">
    <h1 class="text-[26px] font-semibold tracking-tight lg:text-[32px]">Rekam medis</h1>
    <p class="mt-2 max-w-2xl text-sm text-muted lg:text-[15px]">Odontogram, diagnosis ICD-10, tindakan, dan resep dalam satu layar. Riwayat pasien dari Batu dan Malang tetap tersambung.</p>

    @if (session('status'))
        <div class="mt-5 rounded-xl bg-leaf-100 px-5 py-3 text-sm font-medium text-brand-600">{{ session('status') }}</div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_360px]">
        <section class="card overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 pt-5">
                <h2 class="text-lg font-semibold">Pasien hari ini</h2>
                @if ($isDoctor)
                    <div class="flex rounded-lg bg-mist p-1 text-xs">
                        <button type="button" wire:click="$set('mine', true)" @class(['min-h-8 rounded-md px-3', 'bg-white font-semibold shadow-sm' => $mine, 'text-muted' => ! $mine])>Pasien saya</button>
                        <button type="button" wire:click="$set('mine', false)" @class(['min-h-8 rounded-md px-3', 'bg-white font-semibold shadow-sm' => ! $mine, 'text-muted' => $mine])>Semua dokter</button>
                    </div>
                @endif
            </div>

            <ul class="mt-4 divide-y divide-line">
                @forelse ($today as $visit)
                    <li wire:key="v{{ $visit->id }}" class="flex flex-wrap items-center gap-4 px-5 py-4">
                        <span class="flex h-8 w-12 shrink-0 items-center justify-center rounded-full bg-mist text-[13px] text-muted">{{ $visit->queueLabel() }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium">
                                {{ $visit->patient->name }}
                                @if ($visit->patient->hasAllergy())
                                    <span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink">Alergi</span>
                                @endif
                            </p>
                            <p class="mt-1 text-[11px] text-muted">{{ $visit->patient->rmCode() }} • {{ $visit->complaint ?: $visit->purpose }}
                                @if (! $isDoctor || ! $mine) • {{ $visit->doctor->name }} @endif
                                @if ($context->isAll()) • {{ $visit->branch->shortName() }} @endif
                            </p>
                        </div>
                        <span class="badge {{ $visit->status->badgeClass() }}">{{ $visit->status === VisitStatus::Diperiksa ? 'Sedang diperiksa' : $visit->status->label() }}</span>
                        <a href="{{ route('rekam-medis.periksa', $visit) }}" wire:navigate class="btn-soft min-h-10 min-w-[110px] px-4 text-[13px]">
                            {{ $visit->isOpenForExam() && $isDoctor && $visit->doctor->user_id === auth()->id() ? ($visit->medicalRecord ? 'Lanjutkan' : 'Periksa') : 'Lihat' }}
                        </a>
                    </li>
                @empty
                    <li class="px-5 py-12 text-center text-sm text-muted">Belum ada pasien hari ini.</li>
                @endforelse
            </ul>
        </section>

        <section class="card p-5">
            <h2 class="text-lg font-semibold">Cari riwayat pasien</h2>
            <p class="mt-1 text-xs text-muted">Nama, nomor HP, atau nomor RM. Lintas cabang, baca saja.</p>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari pasien…" class="input mt-4">

            <ul class="mt-4 space-y-3">
                @foreach ($patients as $p)
                    <li wire:key="p{{ $p->id }}" class="rounded-xl border border-line p-3.5">
                        <p class="text-sm font-medium">{{ $p->name }} <span class="text-xs font-normal text-muted">• {{ $p->rmCode() }}</span></p>
                        @forelse ($p->visits as $v)
                            <a href="{{ route('rekam-medis.periksa', $v) }}" wire:navigate class="mt-2 flex items-center justify-between text-xs text-brand-600 hover:underline">
                                <span>{{ $v->visit_date->translatedFormat('j M Y') }} • {{ $v->branch->shortName() }} • {{ $v->purpose }}</span>
                                <span aria-hidden="true">→</span>
                            </a>
                        @empty
                            <p class="mt-2 text-xs text-muted">Belum ada rekam medis.</p>
                        @endforelse
                    </li>
                @endforeach
                @if (mb_strlen(trim($search)) >= 2 && $patients->isEmpty())
                    <li class="text-sm text-muted">Pasien tidak ditemukan.</li>
                @endif
            </ul>
        </section>
    </div>
</div>
