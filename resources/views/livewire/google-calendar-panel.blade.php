<div class="space-y-6">
    <section class="card p-5 lg:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-lg font-semibold">Google Calendar</h2>
                <p class="mt-1 max-w-2xl text-sm text-muted">Aplikasi membuat kalender "Egao – nama dokter" untuk setiap dokter dan satu kalender
                    "Egao – Semua janji temu" untuk Owner, lalu mengirim undangan ke emailnya. Penerima cukup membuka email undangan dari Google sekali.
                    Setiap janji baru, diubah, atau dibatalkan otomatis masuk ke kalender dokter yang dituju (bila dokter dikosongkan: dokter yang
                    bertugas di hari itu) dan kalender Owner dalam ±1 menit. Isi acara tanpa nomor HP/NIK.</p>
            </div>
            <span @class(['pill', 'bg-leaf-200 text-brand-700' => $savedEnabled, 'bg-canvas text-muted' => ! $savedEnabled])>{{ $savedEnabled ? 'Aktif' : 'Nonaktif' }}</span>
        </div>

        <div class="mt-5 rounded-xl border border-line p-4">
            <p class="eyebrow">Service Account</p>
            @if ($serviceEmail)
                <p class="mt-2 font-mono text-[13px] break-all">{{ $serviceEmail }}</p>
                <p class="mt-1 text-xs text-brand-600">File kunci terpasang.</p>
            @else
                <p class="mt-2 text-sm text-amber-ink">File kunci Service Account belum dipasang di server. Ikuti panduan di Bantuan → "Menyambungkan Google Calendar".</p>
            @endif
        </div>

        <div class="mt-5 grid gap-4 sm:grid-cols-2">
            <div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="enabled" class="accent-brand-600"> Aktifkan sinkron ke Google Calendar</label>
                <x-error for="enabled" />
            </div>
            <div>
                <label class="label">Jam cadangan bila jam janji kosong</label>
                <input type="time" wire:model="defaultTime" class="input">
                <p class="mt-1 text-xs text-muted">Janji tanpa jam dipasang pada <strong>jam buka cabang hari itu + 1 jam</strong>. Jam ini hanya dipakai bila cabang tutup di hari itu.
                    Durasi acara {{ config('google_calendar.duration_minutes') }} menit.</p>
                <x-error for="defaultTime" />
            </div>
            <div class="sm:col-span-2">
                <label class="label">Email Google Owner</label>
                <textarea wire:model="extraEmails" rows="3" class="input font-mono text-sm" placeholder="satu email per baris"></textarea>
                <p class="mt-1 text-xs text-muted">Diundang ke kalender "Egao – Semua janji temu" (semua cabang). Email dokter diisi di menu Data Dokter.</p>
                <x-error for="extraEmails" />
            </div>
        </div>

        <div class="mt-5 flex justify-end">
            <button type="button" wire:click="saveConfig" wire:loading.attr="disabled" class="btn-primary">Simpan pengaturan</button>
        </div>
    </section>

    <section class="card p-5 lg:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="font-semibold">Kalender &amp; undangan</h3>
                <p class="mt-1 max-w-2xl text-sm text-muted">Google sering tidak mengirim email undangan dari Service Account. Cara pasti: tekan
                    <strong>Salin link kalender</strong>, kirim ke orangnya (mis. lewat WA), lalu ia membukanya sekali saat login dengan Gmail yang terdaftar dan menekan <em>Tambahkan</em>.
                    Link hanya berfungsi untuk email yang terdaftar di sini.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="button" wire:click="prepareCalendars" wire:loading.attr="disabled" wire:target="prepareCalendars" class="btn-soft min-h-9 px-3 text-xs">
                    <span wire:loading.remove wire:target="prepareCalendars">Siapkan kalender</span>
                    <span wire:loading wire:target="prepareCalendars">Menyiapkan…</span>
                </button>
                <button type="button" wire:click="resyncUpcoming" wire:loading.attr="disabled" wire:target="resyncUpcoming" class="btn-ghost min-h-9 px-3 text-xs">
                    <span wire:loading.remove wire:target="resyncUpcoming">Sinkron ulang janji mendatang</span>
                    <span wire:loading wire:target="resyncUpcoming">Mengirim…</span>
                </button>
            </div>
        </div>

        @php
            $ownerCal = $calendars->get('owner');
            $rows = collect($owners)->map(fn ($e) => ['who' => 'Owner', 'email' => $e, 'calendar' => $ownerCal])
                ->concat($doctors->map(fn ($d) => ['who' => $d->name, 'email' => $d->calendar_email ? mb_strtolower($d->calendar_email) : null, 'calendar' => $calendars->get($d->id)]));
        @endphp
        <ul class="mt-4 divide-y divide-line">
            @forelse ($rows as $i => $row)
                @php
                    $cal = $row['calendar'];
                    $share = $cal && $row['email'] ? $cal->shares->firstWhere('email', $row['email']) : null;
                @endphp
                <li wire:key="gcal-r-{{ $i }}" class="flex flex-wrap items-center justify-between gap-2 py-2.5">
                    <div class="min-w-0">
                        <p class="text-sm font-medium">{{ $row['who'] }}</p>
                        @if (! $row['email'])
                            <p class="text-[12px] text-amber-ink">Email Google belum diisi (Data Dokter) — tidak menerima janji.</p>
                        @else
                            <p class="font-mono text-[12px] text-muted break-all">{{ $row['email'] }}</p>
                            <p class="mt-0.5 flex flex-wrap gap-1.5 text-[11px]">
                                <span @class(['pill', 'bg-leaf-100 text-brand-600' => $cal?->calendar_id, 'bg-canvas text-muted' => ! $cal?->calendar_id])>{{ $cal?->calendar_id ? 'Kalender dibuat' : 'Kalender belum dibuat' }}</span>
                                @if ($share?->invited_at)
                                    <span class="pill bg-leaf-100 text-brand-600">Undangan terkirim {{ $share->invited_at->translatedFormat('j M H.i') }}</span>
                                @else
                                    <span class="pill bg-canvas text-muted">Undangan belum terkirim</span>
                                @endif
                                @if ($share?->link_sent_at)
                                    <span class="pill bg-leaf-100 text-brand-600">Link dikirim {{ $share->link_sent_at->translatedFormat('j M H.i') }}</span>
                                @elseif ($share && $cal?->calendar_id)
                                    <span class="pill bg-amber-soft text-amber-ink">Link belum dikirim</span>
                                @endif
                            </p>
                            @if ($err = $share?->error ?? $cal?->error)
                                <p class="mt-0.5 text-[12px] text-rose-ink">{{ $err }}</p>
                            @endif
                        @endif
                    </div>
                    @if ($share && $cal?->calendar_id)
                        <div class="flex flex-wrap gap-2" x-data="{ copied: false }">
                            <a href="{{ $share->whatsappUrl($cal, $row['who']) }}" target="_blank" rel="noopener" wire:click="markLinkSent({{ $share->id }})"
                                @class(['min-h-9 px-3 text-xs', 'btn-primary' => ! $share->link_sent_at, 'btn-soft' => $share->link_sent_at])>Kirim link via WA</a>
                            <button type="button" class="btn-ghost min-h-9 px-3 text-xs"
                                x-on:click="navigator.clipboard?.writeText(@js($cal->addUrl())); copied = true; setTimeout(() => copied = false, 1500); $wire.markLinkSent({{ $share->id }})">
                                <span x-show="! copied">Salin link</span><span x-show="copied" x-cloak>Tersalin</span>
                            </button>
                            <button type="button" wire:click="resendInvite({{ $share->id }})" wire:loading.attr="disabled" class="btn-ghost min-h-9 px-3 text-xs">Kirim ulang undangan</button>
                        </div>
                    @elseif ($row['email'] && $serviceEmail && $savedEnabled)
                        {{-- Email baru / diganti dan belum diundang: siapkan kalender + kirim undangan sekarang. --}}
                        <button type="button" wire:click="prepareCalendars" wire:loading.attr="disabled" wire:target="prepareCalendars" class="btn-primary min-h-9 px-3 text-xs">
                            <span wire:loading.remove wire:target="prepareCalendars">Kirim undangan</span>
                            <span wire:loading wire:target="prepareCalendars">Mengirim…</span>
                        </button>
                    @endif
                </li>
            @empty
                <li class="py-3 text-sm text-muted">Belum ada penerima.</li>
            @endforelse
        </ul>
    </section>

    <section class="card overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-3 p-5 lg:px-6">
            <div>
                <h3 class="font-semibold">Riwayat pengiriman janji</h3>
                <p class="mt-1 text-sm text-muted">Dikirim otomatis tiap ±1 menit. Yang gagal dicoba otomatis hingga {{ $maxAttempts }} kali.
                    @if ($counts['pending']) <span class="font-medium text-amber-ink">{{ $counts['pending'] }} menunggu.</span> @endif
                    @if ($counts['failed']) <span class="font-medium text-rose-ink">{{ $counts['failed'] }} gagal.</span> @endif
                </p>
            </div>
            <select wire:model.live="statusFilter" class="input max-w-[200px] text-sm">
                <option value="">Semua status</option>
                <option value="synced">Masuk kalender</option>
                <option value="pending">Menunggu</option>
                <option value="failed">Gagal</option>
                <option value="removed">Dihapus (batal)</option>
            </select>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[760px] text-left text-sm">
                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                    <tr><th class="py-3 pl-5">Jadwal</th><th>Pasien</th><th>Dokter</th><th>Status</th><th>Kalender</th><th class="pr-5"></th></tr>
                </thead>
                <tbody>
                    @forelse ($deliveries as $a)
                        @php
                            $state = $a->calendarState();
                            $removed = $state === 'synced' && ! in_array($a->status, [\App\Models\Appointment::SCHEDULED, \App\Models\Appointment::ARRIVED], true);
                            [$label, $class] = match (true) {
                                $state === 'failed' => ['Gagal', 'bg-rose-soft text-rose-ink'],
                                $state === 'pending' => ['Menunggu', 'bg-amber-soft text-amber-ink'],
                                $removed => ['Dihapus dari kalender', 'bg-canvas text-muted'],
                                default => ['Masuk kalender', 'bg-leaf-100 text-brand-600'],
                            };
                        @endphp
                        <tr wire:key="gcal-d-{{ $a->id }}" class="border-b border-line align-top last:border-0">
                            <td class="py-2.5 pl-5 text-[12px]">
                                <span class="font-medium">{{ $a->date->translatedFormat('D, j M Y') }}</span>
                                <span class="text-muted">{{ $a->time ? substr($a->time, 0, 5) : '(jam default)' }}</span>
                                <br><span class="pill {{ $a->branch?->badgeClass() }}">{{ $a->branch?->code }}</span>
                                <span class="text-muted">{{ $a->statusLabel() }}</span>
                            </td>
                            <td class="py-2.5 text-[13px]">{{ $a->patient?->name }}<br><span class="text-[11px] text-muted">{{ $a->purpose }}</span></td>
                            <td class="py-2.5 text-[13px]">{{ $a->doctor?->name ?? 'Dokter bertugas' }}</td>
                            <td class="py-2.5">
                                <span class="pill {{ $class }}">{{ $label }}</span>
                                @if ($a->calendar_synced_at && $state !== 'pending')
                                    <p class="mt-1 text-[11px] text-muted">{{ $a->calendar_synced_at->translatedFormat('j M H.i') }}</p>
                                @endif
                                @if ($state === 'failed')
                                    <p class="mt-1 max-w-[260px] text-[11px] text-rose-ink">{{ $a->calendar_error }} ({{ $a->calendar_attempts }}×)</p>
                                @endif
                            </td>
                            <td class="py-2.5 text-[12px] text-muted">
                                @forelse ($a->calendarEvents as $ev)
                                    <p>{{ $calendarTitles[$ev->calendar_id] ?? 'Kalender lain' }}</p>
                                @empty
                                    —
                                @endforelse
                            </td>
                            <td class="py-2.5 pr-5 text-right">
                                @if ($state !== 'synced')
                                    <button type="button" wire:click="resend({{ $a->id }})" wire:loading.attr="disabled" wire:target="resend({{ $a->id }})" class="btn-ghost min-h-8 px-3 text-xs">Kirim sekarang</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-12 text-center text-muted">Belum ada janji yang dikirim.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($deliveries->hasPages())<div class="border-t border-line p-4">{{ $deliveries->links() }}</div>@endif
    </section>
</div>
