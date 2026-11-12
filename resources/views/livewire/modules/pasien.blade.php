@php use App\Support\Format; @endphp
<div class="mx-auto max-w-[1152px]">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']">
        @if ($canRegister)
            <button type="button" wire:click="create" class="btn-primary">+ &nbsp;Pasien baru</button>
        @endif
    </x-page-header>

    <div @class(['mt-7 grid gap-6', 'lg:grid-cols-[minmax(0,1fr)_380px]' => $detail])>
        <section class="card overflow-hidden">
            <div class="border-b border-line p-4 lg:px-5">
                <input type="search" wire:model.live.debounce.300ms="search" class="input" placeholder="Cari nama, no. RM, atau nomor telepon…">
            </div>

            <div class="hidden md:block">
                <table class="w-full text-left text-sm">
                    <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                        <tr>
                            <th class="py-3.5 pl-5">Pasien</th>
                            <th class="py-3.5">Umur</th>
                            <th class="py-3.5">Telepon</th>
                            <th class="py-3.5">Kunjungan terakhir</th>
                            <th class="py-3.5 pr-5"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($patients as $p)
                            <tr wire:key="p-{{ $p->id }}" @class(['border-b border-line last:border-0', 'bg-leaf-50' => $detail?->id === $p->id])>
                                <td class="py-3.5 pl-5">
                                    <p class="font-semibold">{{ $p->name }}</p>
                                    <p class="mt-0.5 text-[11px] text-muted">
                                        {{ $p->rmCode() }}
                                        @if ($p->hasAllergy())<span class="ml-1 text-rose-ink">• Alergi {{ $p->allergies }}</span>@endif
                                    </p>
                                </td>
                                <td class="text-[13px]">{{ $p->age() }} th · {{ $p->gender }}</td>
                                <td class="text-[13px]">{{ $p->maskedPhone() }}</td>
                                <td class="text-[13px]">
                                    @if ($p->latestVisit)
                                        {{ $p->latestVisit->visit_date->translatedFormat('j M Y') }} · {{ $p->latestVisit->branch->shortName() }}
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="pr-5 text-right">
                                    <button type="button" wire:click="open({{ $p->id }})" class="link-arrow">Lihat <span aria-hidden="true">→</span></button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-12 text-center text-muted">{{ $search ? 'Tidak ada pasien yang cocok.' : 'Belum ada pasien.' }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-line md:hidden">
                @forelse ($patients as $p)
                    <li wire:key="pm-{{ $p->id }}">
                        <button type="button" wire:click="open({{ $p->id }})" class="flex w-full items-center justify-between gap-3 px-4 py-3.5 text-left">
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{ $p->name }}</span>
                                <span class="mt-0.5 block text-[11px] text-muted">{{ $p->rmCode() }} · {{ $p->age() }} th @if ($p->hasAllergy())<span class="text-rose-ink">• Alergi</span>@endif</span>
                            </span>
                            <span class="text-muted" aria-hidden="true">→</span>
                        </button>
                    </li>
                @empty
                    <li class="py-10 text-center text-sm text-muted">Tidak ada pasien.</li>
                @endforelse
            </ul>

            @if ($patients->hasPages())
                <div class="border-t border-line p-4">{{ $patients->links() }}</div>
            @endif
        </section>

        @if ($detail)
            <aside class="card p-5 lg:sticky lg:top-[96px] lg:max-h-[calc(100dvh-120px)] lg:overflow-y-auto">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[11px] text-muted">{{ $detail->rmCode() }}</p>
                        <h2 class="mt-1 text-xl font-semibold">{{ $detail->name }}</h2>
                        <p class="mt-1 text-sm text-muted">{{ $detail->genderLabel() }} · {{ $detail->age() }} tahun · {{ $detail->birth_date->translatedFormat('j M Y') }}</p>
                    </div>
                    <button type="button" wire:click="close" class="btn-ghost min-h-9 px-3" aria-label="Tutup">✕</button>
                </div>

                @if ($detail->hasAllergy())
                    <p class="mt-4 rounded-lg bg-rose-soft px-3 py-2 text-sm font-medium text-rose-ink">Alergi: {{ $detail->allergies }}</p>
                @endif

                <dl class="mt-4 grid grid-cols-[110px_1fr] gap-y-2 text-sm">
                    <dt class="text-muted">Telepon</dt>
                    <dd>{{ $reveal ? $detail->phone : $detail->maskedPhone() }}</dd>
                    <dt class="text-muted">NIK</dt>
                    <dd>{{ $detail->nik ? ($reveal ? $detail->nik : $detail->maskedNik()) : '—' }}</dd>
                    <dt class="text-muted">Alamat</dt>
                    <dd>{{ $detail->address ?: '—' }}</dd>
                    <dt class="text-muted">Pekerjaan</dt>
                    <dd>{{ $detail->occupation ?: '—' }}</dd>
                    <dt class="text-muted">Kontak darurat</dt>
                    <dd>{{ $detail->emergency_contact ?: '—' }}</dd>
                </dl>
                <button type="button" wire:click="toggleReveal" class="mt-2 text-xs text-brand-600 hover:underline">{{ $reveal ? 'Samarkan lagi' : 'Tampilkan telepon & NIK (tercatat di audit)' }}</button>

                <div class="mt-5 flex flex-wrap gap-2">
                    @if ($canRegister)
                        <a href="{{ route('antrean', ['pasien' => $detail->id]) }}" wire:navigate class="btn-primary flex-1">Daftarkan ke antrean</a>
                        <button type="button" wire:click="edit({{ $detail->id }})" class="btn-ghost">Ubah data</button>
                    @endif
                </div>

                <h3 class="mt-7 font-semibold">Riwayat kunjungan <span class="text-xs font-normal text-muted">(semua cabang)</span></h3>
                <ol class="mt-3 space-y-3">
                    @forelse ($history as $v)
                        <li wire:key="h-{{ $v->id }}" class="rounded-xl bg-mist p-3.5 text-[13px]">
                            <div class="flex items-center justify-between gap-2">
                                <span class="font-semibold">{{ $v->visit_date->translatedFormat('j M Y') }}</span>
                                <span class="pill {{ $v->branch->badgeClass() }}">{{ $v->branch->shortName() }}</span>
                            </div>
                            <p class="mt-1 text-muted">{{ $v->doctor->name }} · <span class="badge min-w-0 px-2 py-0.5 {{ $v->status->badgeClass() }}">{{ $v->status->label() }}</span></p>
                            @if ($v->diagnoses->isNotEmpty())
                                <p class="mt-1.5">{{ $v->diagnoses->map(fn ($d) => $d->icd10_code.' '.($d->icd10?->name_id ?? ''))->implode('; ') }}</p>
                            @endif
                            @if ($v->invoice && $v->invoice->items->isNotEmpty())
                                <p class="mt-1 text-muted">{{ $v->invoice->items->pluck('name')->implode(', ') }}</p>
                            @endif
                            @if ($v->medicalRecord?->plan)
                                <p class="mt-1 text-muted italic">{{ \Illuminate\Support\Str::limit($v->medicalRecord->plan, 120) }}</p>
                            @endif
                        </li>
                    @empty
                        <li class="text-sm text-muted">Belum pernah berkunjung.</li>
                    @endforelse
                </ol>
            </aside>
        @endif
    </div>

    @if ($showForm)
        <x-modal :title="$editingId ? 'Ubah data pasien' : 'Pasien baru'" wide>
            <form wire:submit="save" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="label">Nama lengkap</label>
                    <input type="text" wire:model="patient.name" class="input" autofocus>
                    <x-error for="patient.name" />
                </div>
                <div>
                    <label class="label">Jenis kelamin</label>
                    <select wire:model="patient.gender" class="input">
                        <option value="P">Perempuan</option>
                        <option value="L">Laki-laki</option>
                    </select>
                </div>
                <div>
                    <label class="label">Tanggal lahir</label>
                    <input type="date" wire:model="patient.birth_date" class="input" max="{{ today()->toDateString() }}">
                    <x-error for="patient.birth_date" />
                </div>
                <div>
                    <label class="label">Nomor telepon / WA</label>
                    <input type="tel" wire:model="patient.phone" class="input" inputmode="tel">
                    <x-error for="patient.phone" />
                </div>
                <div>
                    <label class="label">NIK <span class="font-normal text-muted">(opsional)</span></label>
                    <input type="text" wire:model="patient.nik" class="input" inputmode="numeric" maxlength="16">
                    <x-error for="patient.nik" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Alergi obat / bahan</label>
                    <input type="text" wire:model="patient.allergies" class="input" placeholder="Tidak ada">
                    <x-error for="patient.allergies" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Alamat</label>
                    <textarea wire:model="patient.address" rows="2" class="input py-2.5"></textarea>
                </div>
                <div>
                    <label class="label">Pekerjaan</label>
                    <input type="text" wire:model="patient.occupation" class="input">
                </div>
                <div>
                    <label class="label">Kontak darurat</label>
                    <input type="text" wire:model="patient.emergency_contact" class="input" placeholder="Nama · nomor">
                </div>
                <div class="flex justify-end gap-2 sm:col-span-2">
                    <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
