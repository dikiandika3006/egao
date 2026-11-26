<div>
    @if ($person)
        <div class="flex items-center gap-2"
            x-data="{
                busy: false,
                locate(action) {
                    const testMode = @js($testMode);
                    const send = (lat, lng, acc) => $wire[action](lat, lng, acc).finally(() => this.busy = false);
                    const fail = (text) => { if (testMode) { send(null, null, null) } else { this.busy = false; $dispatch('toast', { message: text }) } };
                    this.busy = true;
                    if (! navigator.geolocation) return fail('Browser tidak mendukung lokasi.');
                    if (! window.isSecureContext) return fail('Lokasi hanya bisa diambil lewat koneksi aman (HTTPS).');
                    navigator.geolocation.getCurrentPosition(
                        p => send(p.coords.latitude, p.coords.longitude, p.coords.accuracy),
                        e => fail('Gagal mengambil lokasi: ' + e.message + '. Izinkan akses lokasi untuk situs ini.'),
                        { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 })
                }
            }">
            @if ($open && ! $compact)
                <span class="text-right text-[11px] leading-tight text-muted">
                    Masuk {{ $open->check_in_at->format('H.i') }}<br>{{ $open->branch?->shortName() }} · {{ $open->shiftLabel() }}
                </span>
            @endif
            @if ($testMode)
                <span class="pill bg-amber-soft text-[10px] text-amber-ink" title="Mode uji: lokasi & jam tidak dicek">Uji</span>
            @endif
            <button type="button" x-bind:disabled="busy" x-on:click="locate('{{ $open ? 'clockOut' : 'checkIn' }}')"
                @class([
                    'inline-flex items-center gap-1.5 rounded-full font-semibold transition disabled:opacity-60',
                    'h-9 px-4 text-[13px]' => ! $compact,
                    'h-7 px-3 text-[12px]' => $compact,
                    'bg-brand-600 text-white hover:bg-brand-700' => ! $open,
                    'bg-rose-ink text-white hover:opacity-90' => $open,
                ])
                title="{{ $open ? 'Clock out shift '.$open->shiftLabel() : 'Check in: cabang & shift terdeteksi otomatis' }}">
                <span x-show="busy" class="size-3 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>
                {{ $open ? 'Clock out' : 'Check in' }}
            </button>
        </div>
    @endif
</div>
