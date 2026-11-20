@php
    use App\Support\Format;
    $conditions = $this->conditions;
    $editable = $this->editable;
    $billing = $this->canEditBilling;
    $toothStyle = function (int $tooth) use ($findingsByTooth, $conditions) {
        $f = $findingsByTooth->get($tooth)?->last();
        $c = $f ? $conditions->get($f['condition_id']) : null;
        return $c ? "background-color: {$c->color}1f; color: {$c->color}; box-shadow: inset 0 0 0 1.5px {$c->color}66" : '';
    };
@endphp

<div class="mx-auto max-w-[1152px] pb-24 lg:pb-0">
    {{-- Judul --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('rekam-medis') }}" wire:navigate class="mb-3 inline-flex text-xs text-muted hover:text-brand-700">← Daftar rekam medis</a>
            <h1 class="text-[26px] font-semibold tracking-tight lg:text-[32px]">Pemeriksaan {{ $patient->name }}</h1>
            <p class="mt-2 text-sm text-muted lg:text-[15px]">
                {{ $visit->visit_date->translatedFormat('j F Y') }} • {{ $visit->doctor->name }} • {{ $visit->branch->name }}
                @if ($record?->version)
                    • Versi {{ $record->version }}
                @endif
            </p>
        </div>
        <span class="pill min-w-[156px] py-1.5 {{ $visit->status->badgeClass() }}">{{ $visit->status === \App\Enums\VisitStatus::Diperiksa ? 'Sedang diperiksa' : $visit->status->label() }}</span>
    </div>

    @if (! $editable)
        <div class="mt-5 rounded-xl bg-indigo-soft px-5 py-3 text-sm text-indigo-ink">
            Mode baca saja.
            @if ($visit->status === \App\Enums\VisitStatus::MenungguPembayaran || $visit->status === \App\Enums\VisitStatus::Selesai)
                Rekam medis sudah dikirim ke kasir.
            @else
                Hanya {{ $visit->doctor->name }} yang dapat mengisi pemeriksaan ini dari {{ $visit->branch->name }}.
            @endif
        </div>
    @elseif ($this->isCorrection)
        <div class="mt-5 rounded-xl bg-orange-soft px-5 py-3 text-sm text-orange-ink">
            Rekam medis sudah final. Perubahan catatan klinis akan disimpan sebagai <strong>versi baru</strong>; tindakan & tagihan tidak berubah.
        </div>
    @endif

    {{-- Header pasien (FR-RM-01) --}}
    <div class="mt-6 grid gap-4 rounded-xl bg-leaf-100 px-5 py-4 sm:grid-cols-[1fr_1.3fr_auto] sm:items-center">
        <div>
            <p class="font-semibold">{{ $patient->rmCode() }}</p>
            <p class="mt-1 text-xs text-muted">{{ $patient->genderLabel() }} • {{ $patient->ageLabel() }}</p>
        </div>
        <div>
            @if ($patient->hasAllergy())
                <p class="text-sm font-semibold text-amber-ink">Alergi: {{ $patient->allergies }}</p>
                <p class="mt-1 text-xs text-muted">Konfirmasi kembali sebelum tindakan</p>
            @else
                <p class="text-sm font-semibold text-brand-600">Alergi: tidak dilaporkan</p>
                <p class="mt-1 text-xs text-muted">Konfirmasi saat anamnesis</p>
            @endif
            <label class="mt-2 inline-flex items-center gap-2 text-xs font-medium">
                <input type="checkbox" wire:model.live="allergyConfirmed" @disabled(! $editable) class="size-4 accent-brand-600">
                Alergi sudah dikonfirmasi ke pasien
            </label>
            @error('allergy_confirmed') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
        </div>
        <button type="button" wire:click="$set('showHistory', true)" class="link-arrow justify-self-start sm:justify-self-end">Riwayat lintas cabang <span aria-hidden="true">→</span></button>
    </div>

    @if ($patient->hasAllergy())
        <div class="mt-3 rounded-xl bg-amber-soft px-5 py-3 text-sm text-amber-ink">
            <strong>Alergi {{ $patient->allergies }}.</strong> Konfirmasi kembali sebelum tindakan.
        </div>
    @endif

    <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_368px]">
        <div class="min-w-0 space-y-6">
            {{-- Peta gigi (FR-RM-03 … 08) --}}
            <section class="card p-5">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold">Peta gigi • ringkasan</h2>
                        <p class="mt-1.5 text-xs text-muted">Tampilan nomor FDI; pilih gigi untuk mencatat temuan.</p>
                    </div>
                    <div class="flex rounded-lg bg-mist p-1 text-xs">
                        @foreach (['adult' => 'Dewasa', 'child' => 'Anak'] as $k => $label)
                            <button type="button" wire:click="$set('dentition', '{{ $k }}')" @class(['min-h-8 rounded-md px-3', 'bg-white font-semibold shadow-sm' => $dentition === $k, 'text-muted' => $dentition !== $k])>{{ $label }}</button>
                        @endforeach
                    </div>
                </div>

                <div class="mt-5 overflow-x-auto pb-1">
                    <div @class(['grid gap-2', 'min-w-[560px]' => count($upper) > 10, 'max-w-[440px]' => count($upper) <= 10])
                         style="grid-template-columns: repeat({{ count($upper) }}, minmax(0, 1fr))">
                        @foreach ([$upper, $lower] as $row)
                            @foreach ($row as $tooth)
                                @php $prev = $this->previousTeeth->get($tooth)?->first(); @endphp
                                <button type="button" wire:click="selectTooth({{ $tooth }})" wire:key="t{{ $tooth }}"
                                        title="Gigi {{ $tooth }}{{ $prev ? ' • terakhir: '.$prev->condition->name : '' }}"
                                        @class([
                                            'relative flex h-9 items-center justify-center rounded-md text-xs transition',
                                            'bg-sky-soft font-semibold text-sky-ink ring-2 ring-sky-ink/40' => $selectedTooth === $tooth,
                                            'bg-mist text-muted hover:bg-line' => $selectedTooth !== $tooth && ! $findingsByTooth->has($tooth),
                                            'font-semibold' => $findingsByTooth->has($tooth),
                                        ])
                                        @if ($selectedTooth !== $tooth && $findingsByTooth->has($tooth)) style="{{ $toothStyle($tooth) }}" @endif>
                                    {{ $tooth }}
                                    @if ($prev)
                                        <span class="absolute top-1 right-1 size-1.5 rounded-full" style="background: {{ $prev->condition->color }}"></span>
                                    @endif
                                </button>
                            @endforeach
                        @endforeach
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
                    @forelse (collect($findings)->pluck('condition_id')->unique() as $cid)
                        @php $c = $conditions->get($cid); @endphp
                        <span class="pill min-w-[120px]" style="background: {{ $c->color }}1f; color: {{ $c->color }}">{{ $c->name }}</span>
                    @empty
                        <span class="text-muted">Belum ada temuan pada kunjungan ini.</span>
                    @endforelse
                    <span class="text-muted">• titik kecil = kondisi dari kunjungan sebelumnya</span>
                </div>

                {{-- Panel gigi terpilih --}}
                @if ($selectedTooth)
                    <div class="mt-5 rounded-xl border border-line p-4">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold">Gigi {{ $selectedTooth }}</p>
                            <button type="button" wire:click="selectTooth({{ $selectedTooth }})" class="text-xs text-muted hover:text-brand-700">Tutup</button>
                        </div>

                        @foreach ($findingsByTooth->get($selectedTooth, collect()) as $f)
                            <p class="mt-2 text-xs"><span class="font-medium" style="color: {{ $conditions->get($f['condition_id'])->color }}">{{ $conditions->get($f['condition_id'])->name }}</span>
                                — {{ (new \App\Models\ToothFinding(['surfaces' => $f['surfaces']]))->surfaceLabel() }}{{ $f['note'] ? ' • '.$f['note'] : '' }}</p>
                        @endforeach

                        @if ($editable)
                            <p class="mt-4 text-xs font-medium text-muted">Permukaan</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($surfaces as $code => $label)
                                    <button type="button" wire:click="toggleSurface('{{ $code }}')" @class([
                                        'min-h-9 rounded-lg border px-3 text-xs capitalize',
                                        'border-sky-ink bg-sky-soft font-semibold text-sky-ink' => in_array($code, $selectedSurfaces, true),
                                        'border-line hover:bg-mist' => ! in_array($code, $selectedSurfaces, true),
                                    ])>{{ $label }}</button>
                                @endforeach
                            </div>
                            <p class="mt-4 text-xs font-medium text-muted">Kondisi</p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($conditions as $c)
                                    <button type="button" wire:click="$set('selectedCondition', {{ $c->id }})" class="min-h-9 rounded-lg border px-3 text-xs"
                                            style="{{ $selectedCondition === $c->id ? "border-color: {$c->color}; background: {$c->color}1f; color: {$c->color}; font-weight: 600" : 'border-color: var(--color-line)' }}">{{ $c->name }}</button>
                                @endforeach
                            </div>
                            <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                                <input type="text" wire:model="findingNote" maxlength="255" placeholder="Catatan (opsional)" class="input">
                                <button type="button" wire:click="addFinding" class="btn-soft shrink-0">Catat temuan</button>
                            </div>
                        @endif

                        @if ($past = $this->previousTeeth->get($selectedTooth))
                            <p class="mt-5 text-xs font-medium text-muted">Riwayat gigi {{ $selectedTooth }}</p>
                            <ul class="mt-2 space-y-1.5 text-xs">
                                @foreach ($past as $p)
                                    <li>{{ $p->visit->visit_date->translatedFormat('j M Y') }} • {{ $p->visit->branch->shortName() }} —
                                        <span style="color: {{ $p->condition->color }}">{{ $p->condition->name }}</span>, {{ $p->surfaceLabel() }}</li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                @elseif ($findings)
                    <p class="mt-3 text-xs text-muted">
                        Dipilih:
                        @foreach ($findings as $f)
                            gigi {{ $f['tooth'] }} • {{ (new \App\Models\ToothFinding(['surfaces' => $f['surfaces']]))->surfaceLabel() }}@if (! $loop->last); @endif
                        @endforeach
                    </p>
                @endif

                @if ($findings)
                    <ul class="mt-4 divide-y divide-line border-t border-line text-sm">
                        @foreach ($findings as $i => $f)
                            <li wire:key="f{{ $i }}" class="flex items-center justify-between gap-3 py-2.5">
                                <span>
                                    <span class="font-medium">Gigi {{ $f['tooth'] }}</span>
                                    <span class="ml-1.5" style="color: {{ $conditions->get($f['condition_id'])->color }}">{{ $conditions->get($f['condition_id'])->name }}</span>
                                    <span class="text-xs text-muted">• {{ (new \App\Models\ToothFinding(['surfaces' => $f['surfaces']]))->surfaceLabel() }}{{ $f['note'] ? ' • '.$f['note'] : '' }}</span>
                                </span>
                                @if ($editable)
                                    <button type="button" wire:click="removeFinding({{ $i }})" class="text-xs text-rose-ink hover:underline">Hapus</button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Catatan kunjungan (FR-RM-09 … 13) --}}
            <section class="card p-5">
                <h2 class="text-xl font-semibold">Catatan kunjungan</h2>

                <label for="complaint" class="mt-5 block text-xs text-muted">Keluhan utama</label>
                <textarea id="complaint" wire:model.blur="complaint" rows="2" maxlength="500" @disabled(! $editable)
                          class="mt-2 w-full resize-none rounded-lg bg-canvas px-3.5 py-3 text-[15px] focus:ring-2 focus:ring-leaf-200 focus:outline-none disabled:text-brand-700"
                          placeholder="Contoh: sakit gigi geraham kanan bawah sejak 3 hari."></textarea>
                <div class="mt-1 flex justify-between text-[11px] text-muted">
                    @error('complaint') <span class="text-rose-ink">{{ $message }}</span> @else <span></span> @enderror
                    <span>{{ mb_strlen($complaint) }}/500</span>
                </div>

                {{-- Diagnosis ICD-10 --}}
                <p class="mt-4 text-xs text-muted">Diagnosis • ICD-10</p>
                <div class="mt-2 space-y-2">
                    @foreach ($diagnoses as $i => $d)
                        <div wire:key="dx{{ $d['code'] }}" class="flex items-center gap-3 rounded-lg bg-canvas px-3.5 py-3">
                            <span class="flex-1 text-[15px]">{{ $this->icdLabels->get($d['code'])?->label() ?? $d['code'] }}</span>
                            @if ($d['primary'])
                                <span class="pill bg-leaf-200 text-brand-700">Utama</span>
                            @elseif ($editable)
                                <button type="button" wire:click="makePrimary({{ $i }})" class="text-xs text-brand-600 hover:underline">Jadikan utama</button>
                            @endif
                            @if ($editable)
                                <button type="button" wire:click="removeDiagnosis({{ $i }})" class="text-xs text-rose-ink hover:underline">Hapus</button>
                            @endif
                        </div>
                    @endforeach
                </div>
                @error('diagnoses') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror

                @if ($editable)
                    <div class="relative mt-2" x-data @click.outside="$wire.set('icdSearch', '')">
                        <input type="search" wire:model.live.debounce.250ms="icdSearch" placeholder="Cari kode atau nama, mis. “karang” atau “K02”" class="input">
                        @if ($this->icdResults->isNotEmpty())
                            <ul class="absolute z-20 mt-1 max-h-72 w-full overflow-y-auto rounded-xl border border-line bg-white p-1 shadow-lg">
                                @foreach ($this->icdResults as $icd)
                                    <li class="flex items-center gap-2">
                                        <button type="button" wire:click="addDiagnosis('{{ $icd->code }}')" class="flex min-h-11 flex-1 flex-col items-start justify-center rounded-lg px-3 py-1.5 text-left hover:bg-mist">
                                            <span class="text-sm"><strong>{{ $icd->code }}</strong> — {{ $icd->name_en }}</span>
                                            @if ($icd->name_id)<span class="text-xs text-muted">{{ $icd->name_id }}</span>@endif
                                        </button>
                                        <button type="button" wire:click="toggleFavorite('{{ $icd->code }}')" title="Favorit" class="px-2 text-lg {{ $this->favorites->contains('code', $icd->code) ? 'text-sun' : 'text-line hover:text-muted' }}">★</button>
                                    </li>
                                @endforeach
                            </ul>
                        @elseif (mb_strlen(trim($icdSearch)) >= 2)
                            <p class="mt-1 text-xs text-muted">Kode tidak ditemukan.</p>
                        @endif
                    </div>
                    @if ($this->favorites->isNotEmpty())
                        <div class="mt-2 flex flex-wrap items-center gap-2">
                            <span class="text-[11px] text-muted">Favorit:</span>
                            @foreach ($this->favorites as $fav)
                                <button type="button" wire:click="addDiagnosis('{{ $fav->code }}')" class="pill bg-mist hover:bg-line" title="{{ $fav->name_en }}">{{ $fav->code }}</button>
                            @endforeach
                        </div>
                    @endif
                @endif

                {{-- Rencana perawatan --}}
                <label for="plan" class="mt-5 block text-xs text-muted">Rencana perawatan</label>
                <textarea id="plan" wire:model.blur="plan" rows="2" @disabled(! $editable)
                          class="mt-2 w-full resize-none rounded-lg bg-canvas px-3.5 py-3 text-[15px] focus:ring-2 focus:ring-leaf-200 focus:outline-none disabled:text-brand-700"
                          placeholder="Contoh: scaling dan edukasi kebersihan mulut."></textarea>
                <div class="mt-2 flex flex-wrap items-center gap-3">
                    <select wire:model.live="followUp" @disabled(! $editable) class="input w-auto min-w-[200px]">
                        @foreach (\App\Livewire\MedicalRecords\Exam::FOLLOW_UPS as $k => $label)
                            <option value="{{ $k }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($followUp)
                        <span class="text-xs text-muted">sekitar {{ $visit->visit_date->copy()->add($followUp)->translatedFormat('j F Y') }}</span>
                    @endif
                </div>

                {{-- Anamnesis tambahan (opsional) --}}
                <details class="group mt-5" @if ($anamnesis || $bloodPressure || $currentMedication) open @endif>
                    <summary class="cursor-pointer text-xs font-medium text-brand-600 select-none">Anamnesis & pemeriksaan tambahan (opsional)</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="text-xs text-muted">Anamnesis</label>
                            <textarea wire:model.blur="anamnesis" rows="2" @disabled(! $editable) class="input mt-1 py-2.5"></textarea>
                        </div>
                        <div>
                            <label class="text-xs text-muted">Tekanan darah</label>
                            <input type="text" wire:model.blur="bloodPressure" placeholder="120/80" @disabled(! $editable) class="input mt-1">
                        </div>
                        <div>
                            <label class="text-xs text-muted">Obat yang sedang dikonsumsi</label>
                            <input type="text" wire:model.blur="currentMedication" @disabled(! $editable) class="input mt-1">
                        </div>
                    </div>
                </details>
            </section>
        </div>

        <div class="space-y-6">
            {{-- Tindakan & tagihan (FR-RM-15 … 19) --}}
            <section class="card p-5">
                <h2 class="text-xl font-semibold">Tindakan & tagihan</h2>
                <p class="mt-1.5 text-xs text-muted">Terisi otomatis untuk kasir • harga {{ $visit->branch->name }}</p>

                <ul class="mt-5 space-y-3.5 text-[15px]">
                    @forelse ($procedures as $i => $p)
                        @php
                            $opt = $this->procedureOptions->get($p['procedure_id']);
                            $standard = $this->standardPrice($p['procedure_id']);
                            $price = $this->priceOf($p);
                        @endphp
                        <li wire:key="p{{ $i }}" class="flex items-start justify-between gap-3" x-data="{ editPrice: false }">
                            <div class="min-w-0">
                                <p>{{ $opt?->name ?? 'Tindakan' }}</p>
                                @if ($p['quantity'] > 1 || $p['tooth'] || $price > $standard)
                                    <p class="text-xs text-muted">{{ $p['quantity'] }} × {{ Format::rupiah($price) }}{{ $p['tooth'] ? ' • gigi '.$p['tooth'] : '' }}
                                        @if ($price > $standard)<span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink">standar {{ Format::rupiah($standard) }}</span>@endif
                                    </p>
                                @endif
                                @if ($billing && $this->canEditPrice)
                                    <button type="button" x-show="! editPrice" x-on:click="editPrice = true" class="mt-1 text-xs font-medium text-brand-600 hover:underline">Ubah harga</button>
                                    <div x-show="editPrice" x-cloak class="mt-1.5 flex items-center gap-2">
                                        <input type="number" min="{{ $standard }}" step="1000" inputmode="numeric"
                                            wire:model.blur="procedures.{{ $i }}.unit_price" placeholder="{{ $standard }}"
                                            class="input h-9 w-36 text-sm" aria-label="Harga {{ $opt?->name }}">
                                        <button type="button" x-on:click="editPrice = false" class="text-xs text-muted hover:underline">Selesai</button>
                                    </div>
                                    <p x-show="editPrice" x-cloak class="mt-1 text-[11px] text-muted">Minimal harga standar {{ Format::rupiah($standard) }}. Kosongkan untuk kembali ke standar.</p>
                                    <x-error for="procedures.{{ $i }}.unit_price" />
                                @endif
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span>{{ Format::rupiah($price * $p['quantity']) }}</span>
                                @if ($billing)
                                    <button type="button" wire:click="removeProcedure({{ $i }})" class="text-muted hover:text-rose-ink" title="Hapus">✕</button>
                                @endif
                            </div>
                        </li>
                    @empty
                        <li class="text-sm text-muted">Belum ada tindakan.</li>
                    @endforelse
                </ul>
                @error('procedures') <p class="mt-2 text-xs text-rose-ink">{{ $message }}</p> @enderror

                @if ($billing)
                    @if ($addingProcedure)
                        <form wire:submit="addProcedure" wire:key="add-procedure" class="mt-4 space-y-2 rounded-xl border border-line p-3">
                            <select wire:model="newProcedureId" class="input">
                                <option value="">Pilih tindakan…</option>
                                @foreach ($this->procedureOptions as $opt)
                                    <option value="{{ $opt->id }}">{{ $opt->name }} — {{ Format::rupiah($opt->prices->first()->price) }}</option>
                                @endforeach
                            </select>
                            @error('newProcedureId') <p class="text-xs text-rose-ink">{{ $message }}</p> @enderror
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" wire:model="newQuantity" min="1" max="99" class="input" aria-label="Jumlah">
                                <input type="text" wire:model="newTooth" maxlength="10" placeholder="Gigi (opsional)" class="input" @if ($selectedTooth) value="{{ $selectedTooth }}" @endif>
                            </div>
                            <div class="flex gap-2">
                                {{-- mousedown.prevent: fokus input tidak lepas, keyboard ponsel tidak menutup & menggeser tombol sebelum klik. --}}
                                <button type="submit" x-on:mousedown.prevent wire:loading.attr="disabled" wire:target="addProcedure" class="btn-primary flex-1">
                                    <span wire:loading.remove wire:target="addProcedure">Tambah</span>
                                    <span wire:loading wire:target="addProcedure">Menambahkan…</span>
                                </button>
                                <button type="button" x-on:mousedown.prevent wire:click="$set('addingProcedure', false)" class="btn-ghost">Batal</button>
                            </div>
                        </form>
                    @else
                        <button type="button" wire:click="$set('addingProcedure', true)" class="mt-5 text-sm font-semibold text-brand-600 hover:underline">+ Tambah tindakan</button>
                    @endif
                @endif

                @if ($this->drugTotal > 0)
                    <div class="mt-4 flex justify-between text-[15px]"><span>Obat dari resep</span><span>{{ Format::rupiah($this->drugTotal) }}</span></div>
                @endif

                <div class="mt-5 border-t border-line pt-5">
                    <p class="text-sm text-muted">Total sementara</p>
                    <p class="mt-2 text-[32px] font-semibold tracking-tight text-brand-600">{{ Format::rupiah($this->total) }}</p>
                </div>

                {{-- Resep (FR-RM-18) --}}
                <div class="mt-4">
                    @if (! $prescriptions)
                        <p class="text-xs text-muted">Resep: tidak ada obat diresepkan</p>
                    @else
                        <p class="text-xs font-medium text-muted">Resep</p>
                        <div class="mt-2 space-y-2">
                            @foreach ($prescriptions as $i => $rx)
                                <div wire:key="rx{{ $i }}" class="grid grid-cols-[1fr_auto] gap-2 rounded-lg bg-canvas p-2.5">
                                    @if ($this->drugOptions->isNotEmpty())
                                        {{-- Obat dari stok klinik: ikut ditagihkan di kasir & mengurangi stok saat lunas --}}
                                        <select wire:model.live="prescriptions.{{ $i }}.item_id" @disabled(! $billing) class="input col-span-2 min-h-9 text-[13px]">
                                            <option value="">Obat luar / tidak dari stok klinik</option>
                                            @foreach ($this->drugOptions as $drug)
                                                @php $left = (int) ($drug->stocks->first()?->quantity ?? 0); @endphp
                                                <option value="{{ $drug->id }}" @disabled($left <= 0 && (int) ($rx['item_id'] ?? 0) !== $drug->id)>{{ $drug->name }} — @rupiah($drug->sell_price)/{{ $drug->unit }} · stok {{ $left }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                    <input type="text" wire:model.blur="prescriptions.{{ $i }}.drug_name" placeholder="Nama obat" @disabled(! $editable) class="input min-h-9 text-[13px]">
                                    @if ($editable)
                                        <button type="button" wire:click="removePrescription({{ $i }})" class="px-2 text-muted hover:text-rose-ink" title="Hapus">✕</button>
                                    @else
                                        <span></span>
                                    @endif
                                    <div class="col-span-2 grid grid-cols-[1fr_88px] gap-2">
                                        <input type="text" wire:model.blur="prescriptions.{{ $i }}.dose" placeholder="Dosis, mis. 3×1 sesudah makan" @disabled(! $editable) class="input min-h-9 text-[13px]">
                                        @if (filled($rx['item_id'] ?? null))
                                            <input type="number" wire:model.blur="prescriptions.{{ $i }}.item_quantity" min="1" max="999" @disabled(! $billing) class="input min-h-9 text-[13px]" title="Jumlah ditagihkan">
                                        @else
                                            <input type="text" wire:model.blur="prescriptions.{{ $i }}.quantity" placeholder="Jumlah" @disabled(! $editable) class="input min-h-9 text-[13px]">
                                        @endif
                                    </div>
                                    @if ($d = filled($rx['item_id'] ?? null) ? $this->drugOptions->get((int) $rx['item_id']) : null)
                                        <p class="col-span-2 text-[11px] text-muted">Ditagihkan {{ max(1, (int) ($rx['item_quantity'] ?? 1)) }} {{ $d->unit }} × @rupiah($d->sell_price) = <b class="text-brand-700">@rupiah($d->sell_price * max(1, (int) ($rx['item_quantity'] ?? 1)))</b></p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        @error('prescriptions') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                    @endif
                    @if ($editable)
                        <button type="button" wire:click="addPrescription" class="mt-2 text-xs font-semibold text-brand-600 hover:underline">+ Tambah obat</button>
                    @endif
                </div>
            </section>

            {{-- Kunjungan sebelumnya (FR-RM-14) --}}
            <section class="card p-5">
                <h2 class="text-xl font-semibold">Kunjungan sebelumnya</h2>
                @if ($lastVisit)
                    <span class="pill mt-4 min-w-[140px] {{ $lastVisit->branch->badgeClass() }}">{{ $lastVisit->branch->name }}</span>
                    <p class="mt-4 text-[15px]">{{ $lastVisit->visit_date->translatedFormat('j F Y') }} • {{ $lastVisit->purpose }}</p>
                    <p class="mt-2 text-sm text-muted">{{ str($lastVisit->medicalRecord->plan)->limit(110) }}</p>
                    <button type="button" wire:click="$set('showHistory', true)" class="link-arrow mt-4">Semua riwayat ({{ $this->history->count() }}) <span aria-hidden="true">→</span></button>
                @else
                    <p class="mt-3 text-sm text-muted">Ini kunjungan pertama pasien.</p>
                @endif
            </section>
        </div>
    </div>

    {{-- Bar aksi (FR-RM-20/21) --}}
    <div class="fixed inset-x-0 bottom-[68px] z-30 border-t border-line bg-canvas/95 px-5 py-3 backdrop-blur lg:static lg:mt-6 lg:border-0 lg:bg-transparent lg:p-0">
        <div class="mx-auto flex max-w-[1152px] flex-wrap items-center justify-between gap-3">
            <p class="hidden text-sm text-muted sm:block">
                @if ($editable) Periksa catatan sebelum disimpan ke rekam medis. @else Dicetak dengan kop klinik & tanda tangan dokter. @endif
            </p>
            <div class="flex w-full gap-3 sm:w-auto">
                @if ($visit->medicalRecord()->exists())
                    <a href="{{ route('rekam-medis.cetak', $visit) }}" target="_blank" class="btn-ghost">Cetak</a>
                @else
                    <button type="button" disabled title="Simpan draf rekam medis dulu agar bisa dicetak." class="btn-ghost cursor-not-allowed opacity-50">Cetak</button>
                @endif
                @if ($billing)
                    <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="btn-soft min-w-[154px] flex-1 sm:flex-none">Simpan draf</button>
                    <button type="button" wire:click="confirmSend" wire:loading.attr="disabled" class="btn-primary min-w-[300px] flex-1 sm:flex-none">Simpan & kirim ke kasir</button>
                @elseif ($this->isCorrection)
                    <button type="button" wire:click="$set('showCorrection', true)" class="btn-primary flex-1 sm:flex-none">Simpan koreksi</button>
                @endif
            </div>
        </div>
    </div>

    {{-- Modal: konfirmasi alergi (UAT-11) --}}
    @if ($showAllergyPrompt)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center" wire:key="allergy-modal">
            <div class="card w-full max-w-md p-6">
                <span class="pill bg-amber-soft text-amber-ink">Periksa sebelum menyimpan</span>
                <h3 class="mt-4 text-lg font-semibold">Alergi pasien belum dikonfirmasi</h3>
                <p class="mt-2 text-sm text-muted">
                    {{ $patient->hasAllergy() ? "Pasien tercatat alergi {$patient->allergies}." : 'Pasien belum melaporkan alergi.' }}
                    Pastikan sudah ditanyakan ke pasien sebelum rekam medis disimpan final dan dikirim ke kasir.
                </p>
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="button" wire:click="confirmAllergyAndSend" class="btn-primary flex-1">Sudah dikonfirmasi, kirim</button>
                    <button type="button" wire:click="$set('showAllergyPrompt', false)" class="btn-ghost">Kembali</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Modal: koreksi (FR-RM-22) --}}
    @if ($showCorrection)
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center">
            <div class="card w-full max-w-md p-6">
                <h3 class="text-lg font-semibold">Simpan koreksi</h3>
                <p class="mt-2 text-sm text-muted">Versi {{ $record?->version }} tetap tersimpan. Koreksi menjadi versi {{ ($record?->version ?? 0) + 1 }}.</p>
                <label class="label mt-4">Alasan koreksi</label>
                <input type="text" wire:model="correctionReason" maxlength="255" class="input" placeholder="Mis. salah input permukaan gigi">
                @error('correctionReason') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="button" wire:click="saveCorrection" class="btn-primary flex-1">Simpan versi baru</button>
                    <button type="button" wire:click="$set('showCorrection', false)" class="btn-ghost">Batal</button>
                </div>
            </div>
        </div>
    @endif

    {{-- Panel: riwayat lintas cabang (FR-RM-02, FR-RM-23) --}}
    @if ($showHistory)
        <div class="fixed inset-0 z-50 flex justify-end bg-brand-800/40" wire:click.self="$set('showHistory', false)">
            <aside class="h-full w-full max-w-lg overflow-y-auto bg-white p-6">
                <div class="flex items-center justify-between">
                    <h3 class="text-lg font-semibold">Riwayat lintas cabang</h3>
                    <button type="button" wire:click="$set('showHistory', false)" class="btn-ghost min-h-9 px-3">Tutup</button>
                </div>
                <p class="mt-1 text-sm text-muted">{{ $patient->name }} • {{ $patient->rmCode() }} • Batu & Malang, terbaru di atas. Baca saja.</p>

                <ol class="mt-6 space-y-4">
                    @forelse ($this->history as $h)
                        <li wire:key="h{{ $h->id }}" class="rounded-xl border border-line p-4">
                            <div class="flex items-center justify-between gap-2">
                                <p class="font-medium">{{ $h->visit_date->translatedFormat('j F Y') }}</p>
                                <span class="pill {{ $h->branch->badgeClass() }}">{{ $h->branch->shortName() }}</span>
                            </div>
                            <p class="mt-1 text-xs text-muted">{{ $h->doctor->name }} • {{ $h->purpose }}</p>
                            @if ($h->diagnoses->isNotEmpty())
                                <p class="mt-3 text-sm">{{ $h->diagnoses->map(fn ($d) => $d->icd10_code.' '.$d->icd10->name_en)->implode('; ') }}</p>
                            @endif
                            @if ($h->findings->isNotEmpty())
                                <p class="mt-1 text-xs text-muted">Gigi: {{ $h->findings->map(fn ($f) => $f->tooth.' '.mb_strtolower($f->condition->name))->implode(', ') }}</p>
                            @endif
                            <p class="mt-2 text-sm text-muted">{{ $h->medicalRecord->plan }}</p>
                            <a href="{{ route('rekam-medis.periksa', $h) }}" wire:navigate class="link-arrow mt-3">Buka <span aria-hidden="true">→</span></a>
                        </li>
                    @empty
                        <li class="text-sm text-muted">Belum ada riwayat kunjungan lain.</li>
                    @endforelse
                </ol>

                @if ($record && $record->versions->isNotEmpty())
                    <h4 class="mt-8 text-sm font-semibold">Versi rekam medis kunjungan ini</h4>
                    <ul class="mt-3 space-y-2 text-xs text-muted">
                        @foreach ($record->versions as $v)
                            <li>Versi {{ $v->version }} • {{ $v->created_at->translatedFormat('j M Y H.i') }} • {{ $v->user?->name ?? '—' }} • {{ $v->reason }}</li>
                        @endforeach
                    </ul>
                @endif
            </aside>
        </div>
    @endif

    <div x-data="{ show: false, message: '' }"
         x-on:toast.window="message = $event.detail.message; show = true; setTimeout(() => show = false, 3000)"
         x-show="show" x-cloak x-transition
         class="fixed bottom-36 left-1/2 z-50 -translate-x-1/2 rounded-xl bg-brand-700 px-5 py-3 text-sm text-white shadow-lg lg:bottom-8"
         x-text="message"></div>
</div>
