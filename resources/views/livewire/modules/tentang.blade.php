<div class="mx-auto max-w-3xl">
    <x-page-header :title="$item['title']" :subtitle="$item['subtitle']" />

    <div class="mt-6 flex gap-1 rounded-xl bg-white p-1 text-sm" role="tablist">
        @foreach ($tabs as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" wire:click="setTab('{{ $key }}')" @class([
                'flex-1 rounded-lg px-4 py-2.5 font-medium transition',
                'bg-brand-600 text-white' => $tab === $key,
                'text-muted hover:bg-mist hover:text-brand-700' => $tab !== $key,
            ])>{{ $label }}</button>
        @endforeach
    </div>

    @if ($tab === 'panduan')
    {{-- Panduan / FAQ yang bisa dicari (App\Support\Faq) --}}
    <section id="panduan" class="card mt-6 p-5 lg:p-6">
        <h2 class="text-lg font-semibold">Panduan &amp; FAQ</h2>
        <p class="mt-1 text-sm text-muted">Cari cara melakukan sesuatu, misalnya "gaji pokok", "diskon", atau "tambah pengguna".</p>

        <div class="relative mt-4">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input wire:model.live.debounce.250ms="search" type="search" class="input pl-10" placeholder="Ketik kata kunci… (mis. gaji, stok, kwitansi)" aria-label="Cari panduan">
        </div>
        @if ($search !== '')
            <p class="mt-2 text-xs text-muted">{{ $faqCount }} hasil untuk "{{ $search }}"</p>
        @endif

        <div class="mt-4 space-y-5">
            @forelse ($faqs as $group => $entries)
                <div wire:key="faq-{{ $group }}">
                    <p class="eyebrow">{{ $group }}</p>
                    <div class="mt-2 space-y-2">
                        @foreach ($entries as $faq)
                            <details class="group overflow-hidden rounded-xl border border-line" @if ($search !== '') open @endif>
                                <summary class="flex cursor-pointer list-none items-start justify-between gap-3 px-4 py-3 hover:bg-mist">
                                    <span class="font-medium">{{ $faq['q'] }}</span>
                                    <span class="mt-0.5 shrink-0 text-xs text-muted transition group-open:rotate-180" aria-hidden="true">▾</span>
                                </summary>
                                <div class="border-t border-line bg-mist/60 px-4 py-3 text-sm">
                                    <p class="leading-relaxed text-brand-700">{{ $faq['a'] }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2 text-xs">
                                        <span class="pill bg-leaf-100 text-brand-600">Menu: {{ $faq['where'] }}</span>
                                        <span class="pill bg-white text-muted">Untuk: {{ $faq['roles'] }}</span>
                                    </div>
                                </div>
                            </details>
                        @endforeach
                    </div>
                </div>
            @empty
                <p class="rounded-xl bg-mist px-4 py-6 text-center text-sm text-muted">Belum ada panduan untuk "{{ $search }}". Coba kata lain, misalnya nama menunya.</p>
            @endforelse
        </div>
    </section>
    @else
    <section class="card mt-6 p-5 lg:p-6">
        <div class="flex flex-wrap items-center gap-4">
            <x-logo />
            <p class="text-sm text-muted">{{ $tagline }}</p>
        </div>
        <dl class="mt-5 grid grid-cols-1 gap-4 text-sm sm:grid-cols-3">
            <div><dt class="eyebrow">Klinik</dt><dd class="mt-1 font-semibold">{{ $clinic?->name }}</dd></div>
            <div><dt class="eyebrow">Versi</dt><dd class="mt-1 font-semibold">{{ $version }} · {{ $lastUpdate }}</dd></div>
            <div><dt class="eyebrow">Platform</dt><dd class="mt-1 font-semibold">{{ $platform }}</dd></div>
            <div class="sm:col-span-3"><dt class="eyebrow">Cabang</dt>
                <dd class="mt-1.5 flex flex-wrap gap-2">
                    @foreach ($branches as $b)<span class="pill {{ $b->badgeClass() }}">{{ $b->name }}</span>@endforeach
                </dd>
            </div>
        </dl>
    </section>

    <section class="card mt-6 p-5 lg:p-6">
        <h2 class="text-lg font-semibold">Aturan sistem</h2>
        <p class="mt-1 text-sm text-muted">Aturan yang berlaku di aplikasi. Klik judul untuk membuka detail.</p>
        <div class="mt-4 space-y-2">
            @foreach ($rules as $group)
                <details class="group overflow-hidden rounded-xl border border-line">
                    <summary class="flex cursor-pointer list-none items-center justify-between gap-3 px-4 py-3 hover:bg-mist">
                        <span class="font-semibold">{{ $group['title'] }}</span>
                        <span class="flex items-center gap-2 text-xs text-muted">{{ count($group['rules']) }} aturan
                            <span class="transition group-open:rotate-180" aria-hidden="true">▾</span></span>
                    </summary>
                    <ul class="space-y-2 border-t border-line bg-mist/60 px-4 py-3 text-sm text-brand-700">
                        @foreach ($group['rules'] as $rule)
                            <li class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-muted"></span><span>{{ $rule }}</span></li>
                        @endforeach
                    </ul>
                </details>
            @endforeach
        </div>
    </section>

    <section class="card mt-6 overflow-hidden">
        <h2 class="px-5 pt-5 text-lg font-semibold lg:px-6">Riwayat perubahan</h2>
        <div class="mt-3 divide-y divide-line">
            @foreach ($changelog as $entry)
                <div class="px-5 py-4 lg:px-6">
                    <p class="font-semibold">{{ $entry['date'] }}</p>
                    <ul class="mt-2 space-y-1.5 text-sm text-muted">
                        @foreach ($entry['changes'] as $c)
                            <li class="flex gap-2"><span class="mt-2 h-1 w-1 shrink-0 rounded-full bg-muted"></span><span>{{ $c }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </section>
    @endif
</div>
