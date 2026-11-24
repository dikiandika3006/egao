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

{{-- Peringatan "belum disimpan": $wire.unsaved (server) atau sedang mengetik di kolom yang belum terkirim. --}}
<div class="mx-auto max-w-[1152px] pb-40 lg:pb-0"
    x-data="{
        typing: false,
        get dirty() { return this.typing || $wire.unsaved },
        mark(e) {
            const model = [...e.target.attributes].find(a => a.name.startsWith('wire:model'));
            if (model && /^(complaint|plan|anamnesis|bloodPressure|currentMedication|prescriptions|procedures)/.test(model.value)) this.typing = true;
        },
        init() {
            this.offCommit = Livewire.hook('commit', ({ component, respond }) => {
                if (component.id === $wire.$id) respond(() => this.typing = false);
            });
            this.onNavigate = (e) => { if (this.dirty && ! confirm('Ada isian pemeriksaan yang belum disimpan. Tinggalkan halaman ini?')) e.preventDefault(); };
            this.onUnload = (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } };
            document.addEventListener('livewire:navigate', this.onNavigate);
            window.addEventListener('beforeunload', this.onUnload);
        },
        destroy() {
            this.offCommit?.();
            document.removeEventListener('livewire:navigate', this.onNavigate);
            window.removeEventListener('beforeunload', this.onUnload);
        },
    }"
    x-on:input="mark($event)" x-on:change="mark($event)">
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
    <div @class(['mt-6 grid gap-4 rounded-xl px-5 py-4 sm:grid-cols-[1fr_1.3fr_auto] sm:items-center', 'bg-amber-soft' => $patient->hasAllergy(), 'bg-leaf-100' => ! $patient->hasAllergy()])>
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
                                — {{ \App\Models\ToothFinding::labelFor($f['surfaces']) }}{{ $f['note'] ? ' • '.$f['note'] : '' }}</p>
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
                            <input type="text" wire:model="findingNote" maxlength="255" placeholder="Catatan (opsional)" class="input mt-3" aria-label="Catatan temuan">
                            {{-- Klik kondisi = temuan langsung tercatat (permukaan & catatan di atas ikut). --}}
                            <p class="mt-4 text-xs font-medium text-muted">Kondisi <span class="font-normal">• klik untuk mencatat</span></p>
                            <div class="mt-2 flex flex-wrap gap-2">
                                @foreach ($conditions as $c)
                                    <button type="button" wire:click="recordFinding({{ $c->id }})" wire:loading.attr="disabled" wire:target="recordFinding"
                                            class="min-h-9 rounded-lg border px-3 text-xs font-medium hover:opacity-80"
                                            style="border-color: {{ $c->color }}66; background: {{ $c->color }}14; color: {{ $c->color }}">+ {{ $c->name }}</button>
                                @endforeach
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
                @endif

                @if ($findings)
                    <ul class="mt-4 divide-y divide-line border-t border-line text-sm">
                        @foreach ($findings as $i => $f)
                            <li wire:key="f{{ $i }}" class="flex items-center justify-between gap-3 py-2.5">
                                <span>
                                    <span class="font-medium">Gigi {{ $f['tooth'] }}</span>
                                    <span class="ml-1.5" style="color: {{ $conditions->get($f['condition_id'])->color }}">{{ $conditions->get($f['condition_id'])->name }}</span>
                                    <span class="text-xs text-muted">• {{ \App\Models\ToothFinding::labelFor($f['surfaces']) }}{{ $f['note'] ? ' • '.$f['note'] : '' }}</span>
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

                {{-- Saran diagnosis dari temuan peta gigi: sekali klik. --}}
                @if ($editable && $this->diagnosisSuggestions->isNotEmpty())
                    <div class="mt-2 rounded-xl bg-leaf-50 p-3">
                        <p class="text-[11px] font-semibold text-brand-600">Saran dari peta gigi</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($this->diagnosisSuggestions as $sd)
                                <button type="button" wire:key="sd{{ $sd->code }}" wire:click="addDiagnosis('{{ $sd->code }}')" wire:loading.attr="disabled"
                                    class="rounded-full border border-leaf-200 bg-white px-3 py-1.5 text-xs font-medium text-brand-600 hover:bg-leaf-100">
                                    + {{ $sd->code }} <span class="text-muted">{{ $sd->name_id ?: $sd->name_en }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($editable)
                    <div class="relative mt-2" x-data @click.outside="if ($wire.icdSearch) $wire.set('icdSearch', '')">
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
                <div class="mt-3 flex flex-wrap items-center gap-1.5">
                    <span class="mr-1 text-xs text-muted">Evaluasi ulang</span>
                    @foreach (\App\Livewire\MedicalRecords\Exam::FOLLOW_UP_CHIPS as $k => $label)
                        <button type="button" wire:click="$set('followUp', '{{ $k }}')" @disabled(! $editable)
                            title="{{ \App\Livewire\MedicalRecords\Exam::FOLLOW_UPS[$k] }}" aria-pressed="{{ $followUp === $k ? 'true' : 'false' }}"
                            @class([
                                'rounded-full border px-3 py-1.5 text-xs font-medium disabled:cursor-not-allowed',
                                'border-brand-600 bg-leaf-100 text-brand-700' => $followUp === $k,
                                'border-line bg-white text-muted hover:border-brand-600 hover:text-brand-700' => $followUp !== $k,
                            ])>{{ $label }}</button>
                    @endforeach
                    @if ($followUp)
                        <span class="text-xs text-muted">sekitar {{ $visit->visit_date->copy()->add($followUp)->translatedFormat('j F Y') }}</span>
                    @endif
                </div>

                {{-- Anamnesis tambahan (opsional) --}}
                <details class="group mt-5" @if ($anamnesis || $bloodPressure || $currentMedication || $errors->has('bloodPressure')) open @endif>
                    <summary class="cursor-pointer text-xs font-medium text-brand-600 select-none">Anamnesis & pemeriksaan tambahan (opsional)</summary>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="text-xs text-muted">Anamnesis</label>
                            <textarea wire:model.blur="anamnesis" rows="2" @disabled(! $editable) class="input mt-1 py-2.5"></textarea>
                        </div>
                        <div>
                            <label class="text-xs text-muted">Tekanan darah</label>
                            <input type="text" wire:model.blur="bloodPressure" placeholder="120/80" maxlength="20" @disabled(! $editable) class="input mt-1">
                            <x-error for="bloodPressure" />
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
            {{-- Tindakan & tagihan (FR-RM-15 … 19): dikelompokkan Tindakan / Obat, item baru disorot sebentar. --}}
            <section class="card p-5" x-data x-init="window.__examReady = false; setTimeout(() => window.__examReady = true, 600)">
                <h2 class="text-xl font-semibold">Tindakan & tagihan</h2>
                <p class="mt-1.5 text-xs text-muted">Terisi otomatis untuk kasir • harga {{ $visit->branch->name }}</p>

                @php
                    $procedureSubtotal = collect($procedures)->sum(fn ($p) => $this->priceOf($p) * max(1, (int) $p['quantity']));
                    $drugLines = collect($prescriptions)->map(function ($rx, $i) {
                        $d = filled($rx['item_id'] ?? null) ? $this->drugOptions->get((int) $rx['item_id']) : null;

                        return $d ? ['i' => $i, 'item' => $d, 'qty' => max(1, (int) ($rx['item_quantity'] ?? 1))] : null;
                    })->filter();
                @endphp

                <div class="mt-5 flex items-baseline justify-between border-b border-line pb-2">
                    <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Tindakan</p>
                    <p class="text-xs text-muted">{{ Format::rupiah($procedureSubtotal) }}</p>
                </div>
                <ul class="mt-3 space-y-1.5 text-[15px]">
                    @forelse ($procedures as $i => $p)
                        @php
                            $opt = $this->procedureOptions->get($p['procedure_id']);
                            $standard = $this->standardPrice($p['procedure_id']);
                            $price = $this->priceOf($p);
                        @endphp
                        <li wire:key="p{{ $i }}-{{ $p['procedure_id'] }}" class="-mx-2 flex items-start justify-between gap-3 rounded-lg px-2 py-1.5"
                            x-data="{ editPrice: false }"
                            x-init="if (window.__examReady) { $el.classList.add('flash-new'); setTimeout(() => $el.classList.remove('flash-new'), 1600) }">
                            <div class="min-w-0">
                                <p>{{ $opt?->name ?? ($p['name'] ?? 'Tindakan') }}</p>
                                @if ($p['quantity'] > 1 || $p['tooth'] || $price > $standard)
                                    <p class="text-xs text-muted">{{ $p['quantity'] }} × {{ Format::rupiah($price) }}{{ $p['tooth'] ? ' • gigi '.$p['tooth'] : '' }}
                                        @if ($price > $standard)<span class="ml-1 rounded bg-amber-soft px-1.5 py-0.5 text-[10px] font-semibold text-amber-ink">standar {{ Format::rupiah($standard) }}</span>@endif
                                    </p>
                                @endif
                                @if (! $opt)
                                    <p class="mt-1 inline-block rounded bg-amber-soft px-1.5 py-0.5 text-[11px] font-medium text-amber-ink">Tidak tersedia / tanpa tarif di {{ $visit->branch->name }}</p>
                                @elseif ($price <= 0)
                                    <p class="mt-1 inline-block rounded bg-amber-soft px-1.5 py-0.5 text-[11px] font-medium text-amber-ink">Tarif Rp0 — cek Master data › Tindakan & tarif</p>
                                @endif
                                @if ($billing && $this->canEditPrice)
                                    <button type="button" x-show="! editPrice" x-on:click="editPrice = true" class="mt-1 block text-xs font-medium text-brand-600 hover:underline">Ubah harga</button>
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

                {{-- Saran dari diagnosis: sekali klik menambah tindakan (gigi = gigi yang dipilih di peta gigi). --}}
                @if ($billing && $this->suggestions->isNotEmpty())
                    <div class="mt-4 rounded-xl bg-leaf-50 p-3">
                        <p class="text-[11px] font-semibold text-brand-600">Saran dari diagnosis{{ $selectedTooth ? ' • gigi '.$selectedTooth : '' }}</p>
                        <div class="mt-2 flex flex-wrap gap-2">
                            @foreach ($this->suggestions as $s)
                                <button type="button" wire:key="sg{{ $s->id }}" wire:click="addSuggestedProcedure({{ $s->id }})" wire:loading.attr="disabled"
                                    class="rounded-full border border-leaf-200 bg-white px-3 py-1.5 text-xs font-medium text-brand-600 hover:bg-leaf-100">
                                    + {{ $s->name }} <span class="text-muted">{{ Format::rupiah($s->prices->first()?->price) }}</span>
                                </button>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if ($billing)
                    @if ($addingProcedure)
                        <form wire:submit="addProcedure" wire:key="add-procedure" class="mt-4 space-y-2 rounded-xl border border-line p-3">
                            {{-- Cari tindakan: ketik nama/kode, pilih dengan klik atau ↑ ↓ Enter. --}}
                            <div class="relative" x-data="{
                                q: '', open: false, active: 0,
                                opts: @js($this->procedureOptions->values()->map(fn ($o) => ['id' => $o->id, 'name' => $o->name, 'code' => $o->code, 'price' => Format::rupiah($o->prices->first()->price), 'zero' => (int) $o->prices->first()->price <= 0])),
                                get list() {
                                    const terms = this.q.toLowerCase().split(/\s+/).filter(Boolean);
                                    return this.opts.filter(o => terms.every(t => (o.code + ' ' + o.name).toLowerCase().includes(t))).slice(0, 50);
                                },
                                pick(o) { this.q = o.name; this.open = false; $wire.$set('newProcedureId', o.id, false); },
                                type() { this.open = true; this.active = 0; $wire.$set('newProcedureId', null, false); },
                                enter(e) { if (this.open && this.list[this.active]) { e.preventDefault(); this.pick(this.list[this.active]); } },
                            }" x-on:click.outside="open = false">
                                <input type="text" x-model="q" x-on:input="type()" x-on:focus="open = true"
                                    x-on:keydown.arrow-down.prevent="open = true; active = Math.min(active + 1, list.length - 1)"
                                    x-on:keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                                    x-on:keydown.enter="enter($event)" x-on:keydown.escape="open = false"
                                    placeholder="Ketik nama/kode tindakan…" autocomplete="off" class="input" aria-label="Tindakan" x-ref="search" x-init="$nextTick(() => $refs.search.focus())">
                                <ul x-show="open" x-cloak class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-line bg-white py-1 text-sm shadow-lg">
                                    <template x-for="(o, i) in list" :key="o.id">
                                        <li>
                                            <button type="button" x-on:mousedown.prevent="pick(o)" x-on:mouseenter="active = i"
                                                :class="i === active ? 'bg-mist' : ''" class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left">
                                                <span><span x-text="o.name"></span> <span class="text-xs text-muted" x-text="o.code"></span></span>
                                                <span class="shrink-0 text-xs" :class="o.zero ? 'rounded bg-amber-soft px-1.5 font-semibold text-amber-ink' : 'text-muted'" x-text="o.price"></span>
                                            </button>
                                        </li>
                                    </template>
                                    <li x-show="list.length === 0" class="px-3 py-2 text-muted">Tindakan tidak ditemukan.</li>
                                </ul>
                            </div>
                            @error('newProcedureId') <p class="text-xs text-rose-ink">{{ $message }}</p> @enderror
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" wire:model="newQuantity" min="1" max="99" class="input" aria-label="Jumlah">
                                <input type="text" wire:model="newTooth" maxlength="10" placeholder="Gigi (opsional)" class="input">
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
                        <button type="button" wire:click="$set('addingProcedure', true)" class="mt-4 text-sm font-semibold text-brand-600 hover:underline">+ Tambah tindakan</button>
                    @endif
                @endif

                @if ($drugLines->isNotEmpty())
                    <div class="mt-6 flex items-baseline justify-between border-b border-line pb-2">
                        <p class="text-[11px] font-semibold tracking-wide text-muted uppercase">Obat dari resep</p>
                        <p class="text-xs text-muted">{{ $drugLines->count() }} item</p>
                    </div>
                    {{-- Rincian per obat ada di kartu Resep; di sini cukup satu baris ringkas. --}}
                    <div wire:key="bill-rx-{{ $drugLines->count() }}-{{ $this->drugTotal }}" class="-mx-2 mt-3 flex items-start justify-between gap-3 rounded-lg px-2 py-1.5 text-[15px]"
                        x-data x-init="if (window.__examReady) { $el.classList.add('flash-new'); setTimeout(() => $el.classList.remove('flash-new'), 1600) }">
                        <p class="min-w-0 text-sm text-muted">{{ $drugLines->map(fn ($l) => $l['item']->name.' ×'.$l['qty'])->implode(', ') }}</p>
                        <span class="shrink-0">{{ Format::rupiah($this->drugTotal) }}</span>
                    </div>
                @endif

                <div class="mt-5 border-t border-line pt-5">
                    <p class="text-sm text-muted">Total sementara</p>
                    <p class="mt-2 text-[32px] font-semibold tracking-tight text-brand-600">{{ Format::rupiah($this->total) }}</p>
                </div>
            </section>

            {{-- Resep (FR-RM-18): kartu tersendiri. Obat dari stok ikut ditagihkan & mengurangi stok saat lunas. --}}
            <section class="card border-l-4 border-l-sky-ink/50 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="text-xl font-semibold">Resep</h2>
                    @if ($prescriptions)<span class="pill bg-sky-soft text-sky-ink">{{ count($prescriptions) }} obat</span>@endif
                </div>
                <p class="mt-1.5 text-xs text-muted">Pilih dari stok {{ $visit->branch->name }} agar ikut ditagihkan, atau tulis obat luar.</p>

                @php
                    $drugJs = $this->drugOptions->values()->map(fn ($d) => [
                        'id' => $d->id, 'name' => $d->name, 'unit' => $d->unit,
                        'price' => Format::rupiah($d->sell_price), 'stock' => (int) ($d->stocks->first()?->quantity ?? 0),
                    ]);
                @endphp

                <div class="mt-4 space-y-3">
                    @forelse ($prescriptions as $i => $rx)
                        @php
                            $chosen = filled($rx['item_id'] ?? null) ? $this->drugOptions->get((int) $rx['item_id']) : null;
                            $left = (int) ($chosen?->stocks->first()?->quantity ?? 0);
                            $need = max(1, (int) ($rx['item_quantity'] ?? 1));
                        @endphp
                        <div wire:key="rx{{ $i }}" class="space-y-2 rounded-xl bg-sky-soft/40 p-3"
                            x-init="if (window.__examReady) { $el.classList.add('flash-new'); setTimeout(() => $el.classList.remove('flash-new'), 1600) }"
                            x-data="{
                                q: @js($chosen?->name ?? ''), open: false, active: 0, days: '',
                                opts: @js($drugJs),
                                get list() {
                                    const terms = this.q.toLowerCase().split(/\s+/).filter(Boolean);
                                    return this.opts.filter(o => terms.every(t => o.name.toLowerCase().includes(t))).slice(0, 30);
                                },
                                pick(o) { if (o.stock <= 0) return; this.q = o.name; this.open = false; $wire.set('prescriptions.{{ $i }}.item_id', o.id); },
                                external() { this.q = ''; this.open = false; $wire.set('prescriptions.{{ $i }}.item_id', ''); },
                                enter(e) { if (this.open && this.list[this.active]) { e.preventDefault(); this.pick(this.list[this.active]); } },
                                dose(part, kind) {
                                    let d = ($wire.prescriptions[{{ $i }}]?.dose || '').trim();
                                    if (kind === 'freq') d = /\d+\s*[×x]\s*\d+/.test(d) ? d.replace(/\d+\s*[×x]\s*\d+/, part) : (part + ' ' + d).trim();
                                    else d = /(sesudah|sebelum) makan/.test(d) ? d.replace(/(sesudah|sebelum) makan/, part) : (d + ' ' + part).trim();
                                    $wire.set('prescriptions.{{ $i }}.dose', d);
                                },
                                perDay() { const m = ($wire.prescriptions[{{ $i }}]?.dose || '').match(/(\d+)\s*[×x]\s*(\d+)/); return m ? m[1] * m[2] : 0; },
                                calc() { const n = this.perDay() * parseInt(this.days || 0); if (n > 0) $wire.set('prescriptions.{{ $i }}.item_quantity', Math.min(n, 999)); },
                            }">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    @if ($billing && $this->drugOptions->isNotEmpty())
                                        <div class="relative" x-on:click.outside="open = false">
                                            <input type="text" x-model="q" x-on:input="open = true; active = 0" x-on:focus="open = true"
                                                x-on:keydown.arrow-down.prevent="open = true; active = Math.min(active + 1, list.length - 1)"
                                                x-on:keydown.arrow-up.prevent="active = Math.max(active - 1, 0)"
                                                x-on:keydown.enter="enter($event)" x-on:keydown.escape="open = false"
                                                placeholder="Ketik nama obat…" autocomplete="off" class="input min-h-10 bg-white text-[14px] font-medium" aria-label="Obat">
                                            <ul x-show="open" x-cloak class="absolute inset-x-0 z-20 mt-1 max-h-64 overflow-y-auto rounded-xl border border-line bg-white py-1 text-sm shadow-lg">
                                                <template x-for="(o, k) in list" :key="o.id">
                                                    <li>
                                                        <button type="button" x-on:mousedown.prevent="pick(o)" x-on:mouseenter="active = k" :disabled="o.stock <= 0"
                                                            :class="[k === active ? 'bg-mist' : '', o.stock <= 0 ? 'opacity-50 cursor-not-allowed' : '']"
                                                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left">
                                                            <span><span x-text="o.name"></span> <span class="text-xs text-muted" x-text="o.price + '/' + o.unit"></span></span>
                                                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[11px] font-semibold"
                                                                :class="o.stock <= 0 ? 'bg-rose-soft text-rose-ink' : (o.stock < 10 ? 'bg-amber-soft text-amber-ink' : 'bg-leaf-100 text-brand-600')"
                                                                x-text="o.stock <= 0 ? 'habis' : 'stok ' + o.stock"></span>
                                                        </button>
                                                    </li>
                                                </template>
                                                <li class="border-t border-line">
                                                    <button type="button" x-on:mousedown.prevent="external()" class="w-full px-3 py-2 text-left text-xs text-muted hover:bg-mist">Obat luar / tidak dari stok klinik</button>
                                                </li>
                                            </ul>
                                        </div>
                                    @elseif ($chosen)
                                        <p class="font-medium">{{ $chosen->name }}</p>
                                    @endif

                                    @if (! $chosen)
                                        <input type="text" wire:model.blur="prescriptions.{{ $i }}.drug_name" placeholder="Nama obat luar" @disabled(! $editable)
                                            class="input mt-2 min-h-9 bg-white text-[13px]" aria-label="Nama obat luar">
                                    @else
                                        <p class="mt-1 text-[11px]">
                                            <span @class(['rounded px-1.5 py-0.5 font-semibold', 'bg-rose-soft text-rose-ink' => $left < $need, 'bg-leaf-100 text-brand-600' => $left >= $need])>stok {{ $left }} {{ $chosen->unit }}</span>
                                            @if ($left < $need)<span class="ml-1 text-rose-ink">stok kurang</span>@endif
                                        </p>
                                    @endif
                                </div>
                                @if ($editable)
                                    <button type="button" wire:click="removePrescription({{ $i }})" class="mt-2 px-1 text-muted hover:text-rose-ink" title="Hapus obat">✕</button>
                                @endif
                            </div>

                            <div>
                                <input type="text" wire:model.blur="prescriptions.{{ $i }}.dose" placeholder="Aturan pakai, mis. 3×1 sesudah makan" @disabled(! $editable)
                                    class="input min-h-9 bg-white text-[13px]" aria-label="Aturan pakai">
                                @if ($editable)
                                    <div class="mt-1.5 flex flex-wrap gap-1.5">
                                        @foreach (['3×1', '2×1', '1×1'] as $f)
                                            <button type="button" x-on:click="dose('{{ $f }}', 'freq')" class="rounded-full border border-line bg-white px-2.5 py-1 text-[11px] font-medium hover:border-sky-ink hover:text-sky-ink">{{ $f }}</button>
                                        @endforeach
                                        @foreach (['sesudah makan', 'sebelum makan'] as $m)
                                            <button type="button" x-on:click="dose('{{ $m }}', 'meal')" class="rounded-full border border-line bg-white px-2.5 py-1 text-[11px] font-medium hover:border-sky-ink hover:text-sky-ink">{{ $m }}</button>
                                        @endforeach
                                    </div>
                                @endif
                            </div>

                            @if ($chosen)
                                <div class="flex flex-wrap items-center gap-2 text-[13px]">
                                    <label class="flex items-center gap-1.5">
                                        <span class="text-muted">Jumlah</span>
                                        <input type="number" wire:model.blur="prescriptions.{{ $i }}.item_quantity" min="1" max="999" @disabled(! $billing)
                                            class="input min-h-9 w-20 bg-white text-[13px]" aria-label="Jumlah ditagihkan">
                                        <span class="text-muted">{{ $chosen->unit }}</span>
                                    </label>
                                    @if ($billing)
                                        <label class="flex items-center gap-1.5" x-show="perDay() > 0">
                                            <span class="text-muted">atau</span>
                                            <input type="number" x-model="days" x-on:change="calc()" min="1" max="60" placeholder="hari"
                                                class="input min-h-9 w-16 bg-white text-[13px]" aria-label="Lama minum (hari)">
                                            <span class="text-muted">hari</span>
                                            <span class="text-[11px] text-muted" x-show="days" x-text="'= ' + (perDay() * parseInt(days || 0))"></span>
                                        </label>
                                    @endif
                                    <span class="ml-auto text-[12px] font-semibold text-brand-700">{{ Format::rupiah($chosen->sell_price * $need) }}</span>
                                </div>
                            @else
                                <input type="text" wire:model.blur="prescriptions.{{ $i }}.quantity" placeholder="Jumlah, mis. 10 tablet" @disabled(! $editable)
                                    class="input min-h-9 bg-white text-[13px]" aria-label="Jumlah">
                            @endif
                        </div>
                    @empty
                        <p class="text-sm text-muted">Tidak ada obat diresepkan.</p>
                    @endforelse
                </div>
                @error('prescriptions') <p class="mt-1 text-xs text-rose-ink">{{ $message }}</p> @enderror
                @if ($editable)
                    <button type="button" wire:click="addPrescription" class="mt-3 text-sm font-semibold text-sky-ink hover:underline">+ Tambah obat</button>
                @endif
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
            @if ($billing)
                {{-- Indikator kelengkapan: wajib (✓ / !) dan opsional (✓ / –). --}}
                <ul class="flex flex-wrap items-center gap-1.5 text-[11px] font-medium" aria-label="Kelengkapan pemeriksaan">
                    @foreach ($this->checklist as [$label, $done, $required])
                        <li @class([
                            'rounded-full px-2.5 py-1',
                            'bg-leaf-100 text-brand-600' => $done,
                            'bg-amber-soft text-amber-ink' => ! $done && $required,
                            'bg-mist text-muted' => ! $done && ! $required,
                        ])>{{ $done ? '✓' : ($required ? '!' : '–') }} {{ $label }}</li>
                    @endforeach
                    <li x-show="dirty" x-cloak class="px-1 text-muted">• belum disimpan</li>
                </ul>
            @else
                <p class="hidden text-sm text-muted sm:block">
                    @if ($editable) Periksa catatan sebelum disimpan ke rekam medis. @else Dicetak dengan kop klinik & tanda tangan dokter. @endif
                </p>
            @endif
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

    {{-- Modal: periksa sebelum kirim — konfirmasi alergi (UAT-11) & tindakan Rp0 dalam satu langkah. --}}
    @if ($showSendReview)
        @php
            $zero = $this->zeroPriceProcedures;
            $rxCount = count($prescriptions);
        @endphp
        <div class="fixed inset-0 z-50 flex items-end justify-center bg-brand-800/40 p-4 sm:items-center" wire:key="send-review-modal">
            <div class="card w-full max-w-md p-6">
                <span class="pill bg-amber-soft text-amber-ink">Periksa sebelum kirim</span>
                <h3 class="mt-4 text-lg font-semibold">Kirim ke kasir {{ $visit->branch->name }}?</h3>

                <ul class="mt-4 space-y-1.5 text-sm">
                    <li class="text-brand-600">✓ Keluhan, {{ count($diagnoses) }} diagnosis, {{ count($procedures) }} tindakan{{ $rxCount ? ", {$rxCount} obat" : '' }}</li>
                    <li class="text-brand-600">✓ Total {{ Format::rupiah($this->total) }}</li>
                </ul>

                <div class="mt-4 space-y-3">
                    @if (! $allergyConfirmed)
                        <div class="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
                            <p class="font-semibold">Alergi belum dikonfirmasi</p>
                            <p class="mt-1">{{ $patient->hasAllergy() ? "Pasien tercatat alergi {$patient->allergies}." : 'Pasien belum melaporkan alergi.' }} Pastikan sudah ditanyakan ke pasien.</p>
                        </div>
                    @endif
                    @if ($zero->isNotEmpty() && ! $zeroPriceConfirmed)
                        <div class="rounded-xl bg-amber-soft px-4 py-3 text-sm text-amber-ink">
                            <p class="font-semibold">Ada tindakan bertarif Rp0</p>
                            <ul class="mt-1 list-disc pl-5">
                                @foreach ($zero as $zp)
                                    <li>{{ $this->procedureOptions->get($zp['procedure_id'])?->name ?? ($zp['name'] ?? 'Tindakan') }}</li>
                                @endforeach
                            </ul>
                            <p class="mt-1">Kalau memang gratis, lanjutkan. Kalau belum, isi tarifnya dulu di Master data › Tindakan & tarif.</p>
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex flex-col gap-2 sm:flex-row-reverse">
                    <button type="button" wire:click="confirmChecksAndSend" wire:loading.attr="disabled" class="btn-primary flex-1">Sudah dicek, kirim</button>
                    <button type="button" wire:click="$set('showSendReview', false)" class="btn-ghost">Kembali</button>
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
