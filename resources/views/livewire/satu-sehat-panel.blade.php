<div class="space-y-6">
    {{-- Pengaturan umum --}}
    <section class="card p-5 lg:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Koneksi SATUSEHAT</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted">Setiap kunjungan yang dikirim dokter ke kasir otomatis dilaporkan ke SATUSEHAT
                    (kunjungan + diagnosis ICD-10) dalam ±5 menit. Kredensial diambil dari satusehat.kemkes.go.id/platform.</p>
            </div>
            <span @class(['pill', 'bg-leaf-200 text-brand-700' => $enabled, 'bg-canvas text-muted' => ! $enabled])>{{ $enabled ? 'Aktif' : 'Nonaktif' }}</span>
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="enabled" class="accent-brand-600"> Aktifkan pengiriman ke SATUSEHAT</label>
            <div>
                <label class="label">Environment</label>
                <select wire:model="env" class="input">
                    @foreach ($environments as $key => $e)
                        <option value="{{ $key }}">{{ $e['label'] }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-muted">Pakai Sandbox untuk uji coba. Kredensial Sandbox dan Production berbeda.</p>
                <x-error for="env" />
            </div>
        </div>

        <div class="mt-6 grid gap-5 lg:grid-cols-2">
            @foreach ($branches as $b)
                <div wire:key="ss-b-{{ $b->id }}" class="rounded-xl border border-line p-4">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-semibold">{{ $b->name }} <span class="pill {{ $b->badgeClass() }}">{{ $b->code }}</span></p>
                        @if ($b->hasSatuSehatCredentials())
                            <span class="pill bg-leaf-100 text-brand-600">Kredensial lengkap</span>
                        @else
                            <span class="pill bg-amber-soft text-amber-ink">Belum lengkap</span>
                        @endif
                    </div>
                    <div class="mt-3 space-y-3">
                        <div><label class="label">Organization ID</label><input type="text" wire:model="creds.{{ $b->id }}.org" class="input font-mono text-sm" autocomplete="off"><x-error for="creds.{{ $b->id }}.org" /></div>
                        <div><label class="label">Client ID</label><input type="text" wire:model="creds.{{ $b->id }}.client" class="input font-mono text-sm" autocomplete="off"><x-error for="creds.{{ $b->id }}.client" /></div>
                        <div>
                            <label class="label">Client Secret @if ($b->satusehat_client_secret)<span class="font-normal text-muted">(tersimpan; kosong = tetap)</span>@endif</label>
                            <input type="password" wire:model="creds.{{ $b->id }}.secret" class="input font-mono text-sm" autocomplete="new-password">
                            <x-error for="creds.{{ $b->id }}.secret" />
                        </div>
                        <p class="text-xs text-muted">Location ruang praktik: {{ $b->satusehat_location_id ? 'sudah dibuat' : 'belum (dibuat otomatis saat kirim pertama)' }}</p>
                    </div>

                    @if ($b->hasSatuSehatCredentials())
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button type="button" wire:click="testConnection({{ $b->id }})" wire:loading.attr="disabled" wire:target="testConnection({{ $b->id }})" class="btn-soft min-h-9 px-3 text-xs">
                                <span wire:loading.remove wire:target="testConnection({{ $b->id }})">Tes koneksi</span>
                                <span wire:loading wire:target="testConnection({{ $b->id }})">Menghubungi…</span>
                            </button>
                            @unless ($b->satusehat_location_id)
                                <button type="button" wire:click="createLocation({{ $b->id }})" wire:loading.attr="disabled" class="btn-ghost min-h-9 px-3 text-xs">Buat Location</button>
                            @endunless
                        </div>
                    @endif
                    @if ($r = $results[$b->id] ?? null)
                        <p @class(['mt-2 text-sm', 'text-brand-600' => $r['ok'], 'text-rose-ink' => ! $r['ok']])>{{ $r['message'] }}</p>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button" wire:click="saveConfig" wire:loading.attr="disabled" class="btn-primary">Simpan pengaturan</button>
        </div>
    </section>

    {{-- Daftar pengiriman --}}
    <section class="card overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 p-5">
            <div>
                <h2 class="text-lg font-semibold">Pengiriman kunjungan</h2>
                <p class="mt-1 text-sm text-muted">
                    @foreach ($statuses as $key => $label){{ $label }}: <b>{{ $counts[$key] ?? 0 }}</b>@if (! $loop->last) · @endif @endforeach
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <select wire:model.live="statusFilter" class="input min-h-9 w-auto py-1 text-sm">
                    <option value="">Semua status</option>
                    @foreach ($statuses as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
                <button type="button" wire:click="sendAll" wire:loading.attr="disabled" wire:target="sendAll" class="btn-soft min-h-9 px-3 text-xs">
                    <span wire:loading.remove wire:target="sendAll">Kirim semua yang tertunda</span>
                    <span wire:loading wire:target="sendAll">Mengirim…</span>
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full min-w-[720px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr><th class="py-3.5 pl-5">Kunjungan</th><th>Pasien</th><th>Dokter</th><th>Status</th><th>Keterangan</th><th class="pr-5"></th></tr>
                </thead>
                <tbody>
                    @forelse ($submissions as $s)
                        <tr wire:key="ss-{{ $s->id }}" class="border-b border-line align-top last:border-0">
                            <td class="py-2.5 pl-5 text-[12px] text-muted">{{ $s->visit?->visit_date?->translatedFormat('j M Y') }}<br>{{ $s->branch?->shortName() }}</td>
                            <td class="py-2.5 text-[13px]">{{ $s->visit?->patient?->name }}</td>
                            <td class="py-2.5 text-[13px]">{{ $s->visit?->doctor?->name }}</td>
                            <td class="py-2.5"><span class="pill {{ $s->badgeClass() }}">{{ $s->statusLabel() }}</span>
                                @if ($s->attempts > 0)<p class="mt-1 text-[11px] text-muted">{{ $s->attempts }}× dicoba</p>@endif</td>
                            <td class="max-w-[320px] py-2.5 text-[12px]">
                                @if ($s->status === \App\Models\SatuSehatSubmission::SENT)
                                    <span class="text-muted">{{ $s->sent_at?->translatedFormat('j M Y H.i') }} · {{ $s->environment === 'production' ? 'Production' : 'Sandbox' }}</span>
                                    @if ($s->encounter_id)
                                        <p class="mt-1 font-mono text-[11px] break-all select-all">Encounter: {{ $s->encounter_id }}</p>
                                    @endif
                                    @foreach ($s->condition_ids ?? [] as $cid)
                                        <p class="font-mono text-[11px] break-all text-muted select-all">Condition: {{ $cid }}</p>
                                    @endforeach
                                @else
                                    <span class="text-rose-ink">{{ $s->last_error }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 pr-5 text-right">
                                @unless ($s->status === \App\Models\SatuSehatSubmission::SENT)
                                    <button type="button" wire:click="resend({{ $s->id }})" wire:loading.attr="disabled" wire:target="resend({{ $s->id }})" class="btn-ghost min-h-8 px-3 text-xs">Kirim ulang</button>
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-muted">Belum ada kunjungan yang dikirim.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($submissions->hasPages())<div class="border-t border-line p-4">{{ $submissions->links() }}</div>@endif
    </section>
</div>
