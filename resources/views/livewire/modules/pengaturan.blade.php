@php use App\Enums\Role; use App\Support\Format; @endphp
<div class="w-full">
    @php $header = $masterItem ?? $item; @endphp
    <x-page-header :title="$header['title']" :subtitle="$header['subtitle']" />

    {{-- Deretan tab hanya untuk pengaturan program; tab data master dibuka dari menu Master data. --}}
    @unless (isset($masterItem))
        <div class="mt-7 flex gap-6 overflow-x-auto border-b border-line text-sm">
            @foreach ($tabs as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" @class([
                    '-mb-px shrink-0 border-b-2 pb-3',
                    'border-leaf-500 font-semibold text-brand-600' => $tab === $key,
                    'border-transparent text-muted hover:text-brand-700' => $tab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </div>
    @endunless

    <div class="mt-6">
        @switch($tab)
            @case('klinik')
                <div class="grid gap-6 lg:grid-cols-2">
                    <section class="card p-5 lg:p-6">
                        <div class="flex items-center justify-between">
                            <h2 class="text-lg font-semibold">Profil klinik</h2>
                            <button type="button" wire:click="editClinic" class="link-arrow">Ubah</button>
                        </div>
                        <dl class="mt-4 grid grid-cols-[150px_1fr] gap-y-2 text-sm">
                            <dt class="text-muted">Nama</dt><dd>{{ $clinic->name }}</dd>
                            <dt class="text-muted">Telepon</dt><dd>{{ $clinic->phone ?: '—' }}</dd>
                            <dt class="text-muted">Penanggung jawab</dt><dd>{{ $clinic->responsible_doctor ?: '—' }}</dd>
                            <dt class="text-muted">Bagi hasil global</dt><dd>{{ rtrim(rtrim(number_format($globalShare, 2, ',', '.'), '0'), ',') }}%</dd>
                        </dl>
                    </section>
                    @foreach ($branches as $b)
                        <section wire:key="b-{{ $b->id }}" class="card p-5 lg:p-6">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-semibold">{{ $b->name }} <span class="pill {{ $b->badgeClass() }}">{{ $b->code }}</span>
                                    @unless ($b->is_active)<span class="pill bg-rose-soft text-rose-ink">Nonaktif</span>@endunless</h2>
                                <button type="button" wire:click="editBranch({{ $b->id }})" class="link-arrow">Ubah</button>
                            </div>
                            <dl class="mt-4 grid grid-cols-[150px_1fr] gap-y-2 text-sm">
                                <dt class="text-muted">Alamat</dt><dd>{{ $b->address ?: '—' }}</dd>
                                <dt class="text-muted">Telepon</dt><dd>{{ $b->phone ?: '—' }}</dd>
                                <dt class="text-muted">WhatsApp</dt><dd>{{ $b->whatsapp ?: '—' }}</dd>
                                <dt class="text-muted">Jam kerja</dt>
                                <dd class="space-y-0.5">
                                    @foreach (\App\Models\Branch::DAY_NAMES as $d => $dayName)
                                        <p><span class="inline-block w-16 text-muted">{{ $dayName }}</span> {{ $b->hoursLabel(now()->startOfWeek(\Carbon\CarbonInterface::MONDAY)->addDays($d - 1)) }}</p>
                                    @endforeach
                                </dd>
                                <dt class="text-muted">Nomor awal kwitansi</dt><dd>{{ $b->receipt_start_number }}</dd>
                                <dt class="text-muted">Catatan kwitansi</dt><dd>{{ $b->receipt_footer ?: '—' }}</dd>
                            </dl>
                        </section>
                    @endforeach
                </div>
                @break

            @case('bagi-hasil')
                <div class="rounded-xl bg-mist px-5 py-4 text-sm">
                    <p class="font-medium">Urutan tarif yang dipakai</p>
                    <p class="mt-1 text-muted">Khusus dokter × tindakan → tarif tindakan → tarif dokter → tarif global. Kolom kosong berarti ikut tarif di bawahnya. Biaya modal (mis. alat behel, x-ray) dikurangkan dari harga tindakan sebelum fee dokter dihitung.</p>
                </div>

                <form wire:submit="saveShares" class="mt-5 space-y-6">
                    @if ($errors->has('shares.doctors.*') || $errors->has('shares.procedures.*'))
                        <p class="rounded-lg bg-rose-soft px-4 py-3 text-sm text-rose-ink">Periksa lagi isian: persentase 0–100 (boleh desimal, mis. 12,5) dan gaji pokok tidak boleh negatif.</p>
                    @endif
                    @if ($errors->has('shares.roles.*') || $errors->has('shares.costs.*'))
                        <p class="rounded-lg bg-rose-soft px-4 py-3 text-sm text-rose-ink">Periksa lagi isian gaji per peran & biaya modal: nominal tidak boleh negatif, fee asisten 0–100%.</p>
                    @endif
                    <div class="grid gap-4 lg:grid-cols-3">
                        <section class="card p-5">
                            <p class="eyebrow">Dokter</p>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <label class="label">Fee tindakan (tarif global)</label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" inputmode="decimal" wire:model="shares.global" class="input">
                                        <span class="text-sm text-muted">%</span>
                                    </div>
                                    <x-error for="shares.global" />
                                    <p class="mt-1 text-[11px] text-muted">Dari harga setelah diskon, dipotong biaya modal, bahan resep & order lab. Dihitung saat tindakan dikirim ke kasir, tidak menunggu lunas.</p>
                                </div>
                                <div>
                                    <label class="label">Uang duduk per shift</label>
                                    <x-money model="shares.roles.doctor_sitting_fee" placeholder="40000" />
                                </div>
                                <div>
                                    <label class="label">Ambang kotor per shift</label>
                                    <x-money model="shares.roles.doctor_sitting_threshold" placeholder="100000" />
                                    <p class="mt-1 text-[11px] text-muted">Shift yang ditandai hadir di Jadwal: kotor tindakan di bawah ambang → uang duduk (fee shift itu 0); sama/lebih → fee %.</p>
                                </div>
                                <div>
                                    <label class="label">Uang duduk per jam <span class="font-normal text-muted">(cara lama, kosongkan bila pakai per shift)</span></label>
                                    <x-money model="shares.roles.doctor_hourly_rate" placeholder="0" />
                                </div>
                                <p class="text-[11px] text-muted">Gaji pokok per dokter diisi di tabel "Per dokter" di bawah.</p>
                            </div>
                        </section>
                        <section class="card p-5">
                            <p class="eyebrow">Asisten</p>
                            <div class="mt-3 space-y-3">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="label">Upah shift pagi</label>
                                        <x-money model="shares.roles.assistant_morning_rate" placeholder="15.000" />
                                    </div>
                                    <div>
                                        <label class="label">Upah shift sore</label>
                                        <x-money model="shares.roles.assistant_evening_rate" placeholder="20.000" />
                                    </div>
                                </div>
                                <p class="text-[11px] text-muted">Per shift hadir (absen lokasi atau centang di Jadwal). Tarif khusus per asisten diisi di Gaji → Karyawan.</p>
                                <div>
                                    <label class="label">Uang duduk per jam <span class="font-normal text-muted">(cara lama, hanya bulan tanpa data absensi)</span></label>
                                    <x-money model="shares.roles.assistant_hourly_rate" placeholder="0" />
                                </div>
                                <div>
                                    <label class="label">Fee dari tindakan yang didampingi</label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" inputmode="decimal" wire:model="shares.roles.assistant_share_percent" class="input" placeholder="2">
                                        <span class="text-sm text-muted">%</span>
                                    </div>
                                    <x-error for="shares.roles.assistant_share_percent" />
                                </div>
                                <div>
                                    <label class="label">Lembur per jam <span class="font-normal text-muted">(jam lembur dari absen pulang)</span></label>
                                    <x-money model="shares.roles.assistant_overtime_rate" placeholder="5.000" />
                                </div>
                                <p class="text-[11px] text-muted">Fee nominal per tindakan (mis. pasang behel/gigi palsu Rp8.000) diatur di Master data › Tindakan.</p>
                            </div>
                        </section>
                        <section class="card p-5">
                            <p class="eyebrow">Admin & staf lain</p>
                            <div class="mt-3 space-y-3">
                                <div>
                                    <label class="label">Lembur per jam</label>
                                    <x-money model="shares.roles.admin_overtime_rate" placeholder="0" />
                                </div>
                                <p class="text-[11px] text-muted">Gaji pokok per orang diisi di menu Gaji → Karyawan.</p>
                                <div class="border-t border-line pt-3">
                                    <label class="label">Target omzet tahunan (bonus akhir tahun)</label>
                                    <x-money model="shares.roles.annual_revenue_target" placeholder="0" />
                                    <p class="mt-1 text-[11px] text-muted">Berlaku untuk semua peran. Menu Gaji menampilkan apakah target tercapai. THR & bonus akhir tahun diisi per orang di menu Gaji.</p>
                                </div>
                                <div class="border-t border-line pt-3">
                                    <label class="label">Biaya admin per transaksi tindakan</label>
                                    <x-money model="shares.roles.admin_fee" placeholder="5000" />
                                    <label class="label mt-3">Biaya pendaftaran & kartu (pasien baru)</label>
                                    <x-money model="shares.roles.registration_fee" placeholder="5000" />
                                    <p class="mt-1 text-[11px] text-muted">Otomatis masuk tagihan pemeriksaan yang ada tindakannya; pendaftaran hanya sekali untuk pasien baru. Tidak untuk penjualan langsung atau tindakan Rp0 (garansi), dan tidak masuk bagi hasil. 0 = tidak dipakai.</p>
                                </div>
                                <div>
                                    <label class="label">Margin harga jual obat/produk</label>
                                    <div class="flex items-center gap-2">
                                        <input type="text" inputmode="decimal" wire:model="shares.roles.default_margin_percent" class="input" placeholder="0">
                                        <span class="text-sm text-muted">%</span>
                                    </div>
                                    <x-error for="shares.roles.default_margin_percent" />
                                    <p class="mt-1 text-[11px] text-muted">Saran harga jual = harga beli + margin (dibulatkan ke atas Rp500). Bisa diatur per barang di Stok.</p>
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="grid gap-6 lg:grid-cols-2">
                        <section class="card overflow-x-auto">
                            <h2 class="px-5 pt-5 text-lg font-semibold">Per dokter</h2>
                            <table class="mt-3 w-full min-w-[460px] text-left text-sm">
                                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                                    <tr><th class="py-3 pl-5">Dokter</th><th class="w-[150px]">Gaji pokok</th><th class="w-[110px] pr-5">Bagi hasil</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($shareDoctors as $d)
                                        <tr wire:key="sd-{{ $d->id }}" @class(['border-b border-line last:border-0', 'opacity-60' => ! $d->is_active])>
                                            <td class="py-2.5 pl-5"><p class="font-medium">{{ $d->name }}</p><p class="text-[11px] text-muted">{{ $d->primaryBranch?->shortName() ?? '—' }}@unless ($d->is_active) · nonaktif @endunless</p></td>
                                            <td class="py-2.5"><x-money model="shares.doctors.{{ $d->id }}.base_salary" class="min-h-10 text-[13px]" /></td>
                                            <td class="py-2.5 pr-5">
                                                <div class="flex items-center gap-1.5">
                                                    <input type="text" inputmode="decimal" wire:model="shares.doctors.{{ $d->id }}.share_percent" class="input min-h-10 text-[13px]" placeholder="{{ $shares['global'] ?? '' }}">
                                                    <span class="text-xs text-muted">%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>

                        <section class="card overflow-x-auto">
                            <h2 class="px-5 pt-5 text-lg font-semibold">Per tindakan</h2>
                            <table class="mt-3 w-full min-w-[500px] text-left text-sm">
                                <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                                    <tr><th class="py-3 pl-5">Tindakan</th><th class="w-[150px]">Biaya modal</th><th class="w-[120px] pr-5">Bagi hasil</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($shareProcedures as $p)
                                        <tr wire:key="sp-{{ $p->id }}" @class(['border-b border-line last:border-0', 'opacity-60' => ! $p->is_active])>
                                            <td class="py-2.5 pl-5"><p class="font-medium">{{ $p->name }}</p><p class="text-[11px] text-muted">{{ $p->category ?: '—' }}</p></td>
                                            <td class="py-2.5 pr-2"><x-money model="shares.costs.{{ $p->id }}" placeholder="0" class="min-h-10 text-[13px]" /></td>
                                            <td class="py-2.5 pr-5">
                                                <div class="flex items-center gap-1.5">
                                                    <input type="text" inputmode="decimal" wire:model="shares.procedures.{{ $p->id }}" class="input min-h-10 text-[13px]" placeholder="ikut dokter">
                                                    <span class="text-xs text-muted">%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </section>
                    </div>

                    <div class="flex justify-end">
                        <button class="btn-primary min-w-[200px]" wire:loading.attr="disabled" wire:target="saveShares">Simpan gaji & bagi hasil</button>
                    </div>
                </form>

                <section class="card mt-6 p-5 lg:p-6">
                    <h2 class="text-lg font-semibold">Tarif khusus dokter × tindakan</h2>
                    <p class="mt-1 text-sm text-muted">Untuk dokter tertentu yang tarifnya berbeda pada tindakan tertentu (mis. drg. Arya 40% untuk Pasang behel).</p>

                    <form wire:submit="addSpecial" class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_120px_auto]">
                        <div>
                            <select wire:model="special.doctor_id" class="input">
                                <option value="">Pilih dokter</option>
                                @foreach ($shareDoctors as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                            </select>
                            <x-error for="special.doctor_id" />
                        </div>
                        <div>
                            <select wire:model="special.procedure_id" class="input">
                                <option value="">Pilih tindakan</option>
                                @foreach ($shareProcedures as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                            </select>
                            <x-error for="special.procedure_id" />
                        </div>
                        <div>
                            <div class="flex items-center gap-1.5">
                                <input type="text" inputmode="decimal" wire:model="special.share_percent" class="input" placeholder="0">
                                <span class="text-sm text-muted">%</span>
                            </div>
                            <x-error for="special.share_percent" />
                        </div>
                        <button class="btn-soft">Tambah</button>
                    </form>

                    <ul class="mt-4 divide-y divide-line text-sm">
                        @forelse ($specials as $s)
                            <li wire:key="sx-{{ $s->id }}" class="flex items-center justify-between gap-3 py-2.5">
                                <span><b>{{ $s->doctor->name }}</b> · {{ $s->procedure->name }}</span>
                                <span class="flex items-center gap-4">
                                    <b>{{ rtrim(rtrim(number_format((float) $s->share_percent, 2, ',', '.'), '0'), ',') }}%</b>
                                    @if ($canEdit)<button type="button" wire:click="removeSpecial({{ $s->id }})" wire:confirm="Hapus tarif khusus ini?" class="text-xs text-rose-ink hover:underline">Hapus</button>@endif
                                </span>
                            </li>
                        @empty
                            <li class="py-2.5 text-muted">Belum ada tarif khusus.</li>
                        @endforelse
                    </ul>
                </section>
                @break

            @case('aplikasi')
                <form wire:submit="saveApp" class="grid gap-6 lg:grid-cols-2">
                    <section class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Identitas & logo</h2>
                        <p class="mt-1 text-xs text-muted">Nama & telepon klinik diubah di tab Klinik & cabang.</p>
                        <div class="mt-4 space-y-4">
                            <div class="flex items-center gap-4">
                                <div class="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-mist">
                                    @if ($logo && method_exists($logo, 'temporaryUrl') && str_starts_with((string) $logo->getMimeType(), 'image/'))
                                        <img src="{{ $logo->temporaryUrl() }}" alt="" class="size-full object-contain">
                                    @elseif ($clinic->logo_path)
                                        <img src="{{ asset('storage/'.$clinic->logo_path) }}" alt="Logo klinik" class="size-full object-contain">
                                    @else
                                        <img src="{{ asset('images/logo.png') }}" alt="Logo bawaan" class="size-full object-contain">
                                    @endif
                                </div>
                                <div class="min-w-0 text-sm">
                                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp" class="block w-full text-sm">
                                    <p class="mt-1 text-xs text-muted">PNG/JPG ≤ 2 MB. Dipakai di kwitansi.</p>
                                    <x-error for="logo" />
                                    @if ($clinic->logo_path)
                                        <button type="button" wire:click="removeLogo" wire:confirm="Hapus logo dan kembali ke logo bawaan?" class="mt-1 text-xs text-rose-ink hover:underline">Hapus logo</button>
                                    @endif
                                </div>
                            </div>
                            <div><label class="label">Tagline</label><input type="text" wire:model="app.clinic_tagline" class="input"><x-error for="app.clinic_tagline" /></div>
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div><label class="label">Email</label><input type="email" wire:model="app.clinic_email" class="input"><x-error for="app.clinic_email" /></div>
                                <div><label class="label">WhatsApp (cadangan)</label><input type="tel" wire:model="app.clinic_whatsapp" class="input" title="Dipakai di kwitansi bila WA cabang kosong"><x-error for="app.clinic_whatsapp" /></div>
                            </div>
                        </div>
                    </section>

                    <section class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Kwitansi</h2>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="label">Prefix nomor kwitansi</label>
                                <input type="text" wire:model.live.debounce.300ms="app.receipt_prefix" maxlength="5" class="input uppercase" placeholder="KW">
                                <x-error for="app.receipt_prefix" />
                                <p class="mt-1 text-xs text-muted">Contoh nomor: <b>{{ \App\Support\Receipts::preview($app['receipt_prefix'] ?? '', $branches->first()?->code ?? 'BTU') }}</b>. Berlaku untuk kwitansi berikutnya.</p>
                            </div>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="app.receipt_show_logo" class="accent-brand-600"> Tampilkan logo di kwitansi</label>
                        </div>

                        <h2 class="mt-7 text-lg font-semibold">Kasir</h2>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label class="label">Metode bayar aktif</label>
                                <div class="flex flex-wrap gap-x-5 gap-y-2 text-sm">
                                    @foreach ($methods as $key => $label)
                                        <label class="flex items-center gap-2"><input type="checkbox" wire:model="app.payment_methods" value="{{ $key }}" class="accent-brand-600"> {{ $label }}</label>
                                    @endforeach
                                </div>
                                <x-error for="app.payment_methods" />
                            </div>
                            <div>
                                <label class="label">Batas diskon dokter (% dari subtotal)</label>
                                <input type="number" min="0" max="100" wire:model="app.admin_max_discount_percent" class="input">
                                <x-error for="app.admin_max_discount_percent" />
                                <p class="mt-1 text-xs text-muted">Diskon diberikan dokter saat pemeriksaan. Owner tidak dibatasi; Admin/Asisten yang diizinkan memeriksa (Hak akses peran › Rekam medis › Edit) memakai batas yang sama. 0 = tidak boleh memberi diskon.</p>
                            </div>
                        </div>
                    </section>

                    <section class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Keamanan</h2>
                        <div class="mt-4">
                            <label class="label">Keluar otomatis bila tidak aktif (jam)</label>
                            <input type="number" min="1" max="{{ max(1, intdiv((int) config('session.lifetime'), 60)) }}" wire:model="app.session_idle_hours" class="input">
                            <p class="mt-1 text-xs text-muted">Maksimal {{ max(1, intdiv((int) config('session.lifetime'), 60)) }} jam (mengikuti SESSION_LIFETIME server).</p>
                            <x-error for="app.session_idle_hours" />
                        </div>
                    </section>

                    <section class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Pemeliharaan</h2>
                        <p class="mt-1 text-sm text-muted">Jalankan setelah mengunggah versi aplikasi baru: migrasi database tertunda & bersihkan cache. Data tidak dihapus, seeder tidak dijalankan.</p>
                        <p class="mt-3 text-sm">Migrasi tertunda: <b @class(['text-amber-ink' => $pendingMigrations > 0])>{{ $pendingMigrations }}</b></p>
                        <button type="button" wire:click="runMaintenance" wire:confirm="Jalankan migrasi database & bersihkan cache sekarang?" wire:loading.attr="disabled" wire:target="runMaintenance" class="btn-soft mt-3">
                            <span wire:loading.remove wire:target="runMaintenance">Update database</span>
                            <span wire:loading wire:target="runMaintenance">Memproses…</span>
                        </button>
                        @if ($maintenanceLog)
                            <pre class="mt-3 max-h-48 overflow-auto rounded-lg bg-mist p-3 text-[11px] whitespace-pre-wrap">{{ $maintenanceLog }}</pre>
                        @endif
                    </section>

                    <div class="flex justify-end lg:col-span-2">
                        <button class="btn-primary min-w-[200px]" wire:loading.attr="disabled" wire:target="saveApp,logo">Simpan pengaturan</button>
                    </div>
                </form>
                @break

            @case('absensi')
                <div class="grid gap-6 lg:grid-cols-2">
                    <form wire:submit="saveAttendance" class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Aturan check in</h2>
                        <p class="mt-1 text-xs text-muted">Dokter & asisten cukup tekan Check in lalu Clock out. Cabang dideteksi dari lokasi terdekat dalam radius, shift dari jam kerja cabang.</p>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <label class="label">Radius lokasi (meter)</label>
                                <input type="number" min="10" max="2000" wire:model="attendance.radius_m" class="input">
                                <p class="mt-1 text-[11px] text-muted">Jarak maksimal dari titik klinik.</p>
                                <x-error for="attendance.radius_m" />
                            </div>
                            <div>
                                <label class="label">Batas akurasi GPS (meter)</label>
                                <input type="number" min="10" max="500" wire:model="attendance.max_accuracy_m" class="input">
                                <p class="mt-1 text-[11px] text-muted">Sinyal lebih kasar dari ini ditolak.</p>
                                <x-error for="attendance.max_accuracy_m" />
                            </div>
                        </div>
                        <label class="mt-5 flex items-start gap-3 rounded-xl border border-line p-3">
                            <input type="checkbox" wire:model="attendance.test_mode" class="mt-0.5 accent-brand-600">
                            <span class="text-sm">
                                <span class="font-semibold">Mode uji</span>
                                <span class="block text-[11px] text-muted">Lokasi & jam tidak dicek; cabang = pilihan di header. Absen ditandai "Uji" di rekap dan tetap terhitung di gaji, jadi hapus lewat Koreksi bila tidak dipakai. Matikan sebelum dipakai sungguhan.</span>
                            </span>
                        </label>
                        <div class="mt-5 flex justify-end"><button type="submit" class="btn-primary">Simpan</button></div>
                    </form>

                    <section class="card p-5 lg:p-6">
                        <h2 class="text-lg font-semibold">Titik lokasi cabang</h2>
                        <p class="mt-1 text-xs text-muted">Koordinat diisi di data cabang (Master › Cabang).</p>
                        <ul class="mt-4 divide-y divide-line text-sm">
                            @foreach ($locationBranches as $b)
                                <li wire:key="loc-{{ $b->id }}" class="flex items-center justify-between gap-3 py-3">
                                    <div>
                                        <p class="font-semibold">{{ $b->name }} @unless ($b->is_active)<span class="text-xs font-normal text-muted">(nonaktif)</span>@endunless</p>
                                        <p class="text-xs text-muted">{{ $b->hasLocation() ? $b->latitude.', '.$b->longitude : 'Belum diatur — check in di cabang ini belum bisa' }}</p>
                                    </div>
                                    <button type="button" wire:click="editBranch({{ $b->id }})" class="link-arrow">Atur</button>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </div>
                @break

            @case('tindakan')
                <div class="mb-4 flex flex-wrap items-center justify-end gap-2">
                    <input type="search" wire:model.live.debounce.300ms="procedureSearch" class="input mr-auto w-full sm:max-w-sm" placeholder="Cari kode, nama, atau kategori tindakan…">
                    @if ($canEdit)<button type="button" wire:click="openProcedureImport" class="btn-soft">Impor Excel</button>@endif
                    @if ($canEdit)<button type="button" wire:click="editProcedure" class="btn-primary">+ &nbsp;Tindakan</button>@endif
                </div>
                <section class="card overflow-x-auto">
                    <table class="w-full min-w-[720px] text-left text-sm">
                        <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                            <tr><th class="py-3.5 pl-5">Kode</th><th>Tindakan</th><th>Kategori</th>
                                @foreach ($branches as $b)<th class="text-right">{{ $b->shortName() }}</th>@endforeach
                                <th class="text-right">Bagi hasil</th><th class="pr-5"></th></tr>
                        </thead>
                        <tbody>
                            @forelse ($procedures as $p)
                                <tr wire:key="pr-{{ $p->id }}" @class(['border-b border-line last:border-0', 'opacity-50' => ! $p->is_active])>
                                    <td class="py-3 pl-5 font-mono text-[12px]">{{ $p->code }}</td>
                                    <td class="font-medium">{{ $p->name }}</td>
                                    <td class="text-[13px] text-muted">{{ $p->category ?: '—' }}</td>
                                    @foreach ($branches as $b)
                                        <td class="text-right">
                                            @if ($range = $p->rangeFor($b->id))
                                                {{ Format::rupiah($range[0]) }}@if ($range[1])<span class="text-muted"> – {{ Format::rupiah($range[1]) }}</span>@endif
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endforeach
                                    <td class="text-right text-[13px]">{{ $p->share_percent !== null ? rtrim(rtrim($p->share_percent, '0'), '.').'%' : 'Ikut dokter' }}</td>
                                    <td class="pr-5 text-right">@if ($canEdit)<button type="button" wire:click="editProcedure({{ $p->id }})" class="link-arrow">Ubah</button>@endif</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ 5 + $branches->count() }}" class="py-12 text-center text-muted">{{ $procedureSearch ? 'Tidak ada tindakan yang cocok.' : 'Belum ada tindakan.' }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($procedures->hasPages())<div class="border-t border-line p-4">{{ $procedures->links() }}</div>@endif
                </section>
                <p class="mt-3 text-xs text-muted">Perubahan tarif tidak mengubah tagihan yang sudah dibuat.</p>
                @break

            @case('pengguna')
                <div class="mb-4 flex justify-end"><button type="button" wire:click="editUser" class="btn-primary">+ &nbsp;Pengguna</button></div>
                <section class="card overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                            <tr><th class="py-3.5 pl-5">Nama</th><th>Username</th><th>Peran</th><th>Cabang</th><th class="pr-5"></th></tr>
                        </thead>
                        <tbody>
                            @foreach ($users as $u)
                                <tr wire:key="u-{{ $u->id }}" @class(['border-b border-line last:border-0', 'opacity-50' => ! $u->is_active])>
                                    <td class="py-3 pl-5 font-semibold">{{ $u->name }}</td>
                                    <td class="text-[13px]">{{ $u->username }}</td>
                                    <td class="text-[13px]">{{ $u->roleList()->map->label()->implode(', ') }}</td>
                                    <td class="text-[13px]">{{ $u->hasRole(Role::Owner) ? 'Semua cabang' : $u->branches->map->shortName()->implode(', ') }}</td>
                                    <td class="pr-5 text-right"><button type="button" wire:click="editUser({{ $u->id }})" class="link-arrow">Ubah</button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
                @break

            @case('hak-akses')
                <section class="card p-5 lg:p-6">
                    <h2 class="font-semibold">Hak akses menu per peran</h2>
                    <p class="mt-1 text-sm text-muted">
                        <b>Lihat</b> = menu tampil & halaman bisa dibuka. <b>Edit</b> = boleh menambah/mengubah data. <b>Keuangan</b> = angka rupiah tampil (bila mati tertulis Rp•••).
                        Owner selalu akses penuh. Satu akun bisa punya beberapa peran (atur di Master data › Master user); izin mengikuti peran yang sedang dipilih saat login.
                    </p>
                </section>
                @php
                    // Tiap peran satu blok warna: Admin/Asisten biru, Dokter hijau.
                    $roleStyle = [
                        'admin' => ['head' => 'bg-sky-soft text-sky-ink', 'cell' => 'bg-sky-soft/35', 'check' => 'accent-sky-ink'],
                        'dokter' => ['head' => 'bg-leaf-100 text-brand-600', 'cell' => 'bg-leaf-50/60', 'check' => 'accent-brand-600'],
                    ];
                    $menuGroups = collect($accessMenus)->groupBy(fn ($m) => $m['group'], preserveKeys: true)
                        ->sortBy(fn ($rows, $g) => array_search($g, array_keys(\App\Support\Navigation::GROUPS)));
                @endphp
                <section class="card mt-4 overflow-x-auto">
                    <table class="w-full min-w-[820px] border-separate border-spacing-0 text-left text-sm">
                        <thead class="text-[10px] font-medium uppercase">
                            <tr>
                                <th class="bg-canvas/70 py-3 pl-5 text-muted" rowspan="2">Menu</th>
                                @foreach ($accessRoles as $r)
                                    <th colspan="3" class="border-l-8 border-white pt-3 pb-1 text-center text-[11px] font-semibold tracking-wide {{ $roleStyle[$r->value]['head'] ?? 'bg-mist' }}">{{ $r->label() }}</th>
                                @endforeach
                            </tr>
                            <tr>
                                @foreach ($accessRoles as $r)
                                    @foreach (\App\Support\Access::ABILITIES as $label)
                                        <th @class(['pb-2.5 text-center font-normal', $roleStyle[$r->value]['head'] ?? 'bg-mist', 'border-l-8 border-white' => $loop->first])>{{ $label }}</th>
                                    @endforeach
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($menuGroups as $group => $rows)
                                <tr wire:key="acc-g-{{ $group }}">
                                    <td colspan="{{ 1 + 3 * count($accessRoles) }}" class="border-b border-line bg-canvas/70 px-5 pt-4 pb-1.5 text-[10px] font-semibold tracking-wide text-muted uppercase">
                                        {{ \App\Support\Navigation::GROUPS[$group] ?? ucfirst($group) }}
                                    </td>
                                </tr>
                                @foreach ($rows as $menuKey => $menu)
                                    <tr wire:key="acc-{{ $menuKey }}">
                                        <td class="border-b border-line py-2.5 pl-5 font-semibold">{{ $menu['label'] }}</td>
                                        @foreach ($accessRoles as $r)
                                            @php $p = $access[$r->value][$menuKey] ?? ['lihat' => false, 'edit' => false, 'keuangan' => false]; $st = $roleStyle[$r->value] ?? ['cell' => '', 'check' => 'accent-brand-600']; @endphp
                                            @foreach (array_keys(\App\Support\Access::ABILITIES) as $ab)
                                                <td @class(['border-b border-line text-center', $st['cell'], 'border-l-8 border-l-white' => $loop->first])>
                                                    @if ($menuKey === 'dashboard' && $ab !== 'keuangan')
                                                        <span class="text-[11px] text-muted" title="Dashboard adalah halaman awal; yang diatur hanya angka pendapatan.">selalu</span>
                                                    @elseif ($ab === 'keuangan' && ! in_array($menuKey, \App\Support\Access::FINANCE_MENUS, true))
                                                        <span class="text-[11px] text-muted" title="{{ in_array($menuKey, \App\Support\Access::MONEY_ONLY_MENUS, true) ? 'Isi menu ini seluruhnya angka uang: ikut izin Lihat.' : 'Menu ini tidak memuat angka uang.' }}">—</span>
                                                    @elseif ($ab === 'lihat')
                                                        <input type="checkbox" class="size-4 {{ $st['check'] }}" wire:model.live="access.{{ $r->value }}.{{ $menuKey }}.lihat"
                                                            aria-label="{{ $r->label() }} Lihat {{ $menu['label'] }}">
                                                    @else
                                                        <input type="checkbox" class="size-4 disabled:opacity-30 {{ $st['check'] }}" wire:model="access.{{ $r->value }}.{{ $menuKey }}.{{ $ab }}"
                                                            aria-label="{{ $r->label() }} {{ \App\Support\Access::ABILITIES[$ab] }} {{ $menu['label'] }}"
                                                            @disabled($menuKey !== 'dashboard' && ! $p['lihat'])>
                                                    @endif
                                                </td>
                                            @endforeach
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </section>
                <div class="mt-4 flex flex-wrap justify-end gap-2">
                    <button type="button" wire:click="resetAccess" wire:confirm="Kembalikan semua hak akses ke bawaan?" class="btn-ghost">Kembalikan bawaan</button>
                    <button type="button" wire:click="saveAccess" class="btn-primary">Simpan hak akses</button>
                </div>
                @break

            @case('kategori')
                <div class="mb-4 flex justify-end">@if ($canEdit)<button type="button" wire:click="editCategory" class="btn-primary">+ &nbsp;Kategori</button>@endif</div>
                <section class="card">
                    <ul class="divide-y divide-line">
                        @foreach ($categories as $c)
                            <li wire:key="c-{{ $c->id }}" class="flex items-center justify-between px-5 py-3.5 text-sm">
                                <span>{{ $c->name }} <span class="text-xs text-muted">· {{ $c->expenses_count }} catatan</span>
                                    @if ($c->is_system)<span class="pill ml-1 bg-indigo-soft px-2 py-0.5 text-[10px] text-indigo-ink">Otomatis</span>@endif</span>
                                @unless ($c->is_system)
                                    @if ($canEdit)<button type="button" wire:click="editCategory({{ $c->id }})" class="link-arrow">Ubah</button>@endif
                                @endunless
                            </li>
                        @endforeach
                    </ul>
                </section>
                @break

            @case('satusehat')
                <livewire:satu-sehat-panel />
                @break

            @case('google-calendar')
                <livewire:google-calendar-panel />
                @break

            @case('audit')
                <section class="card overflow-x-auto">
                    <table class="w-full min-w-[640px] text-left text-sm">
                        <thead class="bg-canvas/70 text-[10px] font-medium text-muted uppercase">
                            <tr><th class="py-3.5 pl-5">Waktu</th><th>Pengguna</th><th>Aksi</th><th class="pr-5">Objek</th></tr>
                        </thead>
                        <tbody>
                            @forelse ($logs as $log)
                                <tr class="border-b border-line last:border-0">
                                    <td class="py-2.5 pl-5 text-[12px] text-muted">{{ $log->created_at?->translatedFormat('j M Y H.i') }}</td>
                                    <td class="text-[13px]">{{ $log->user?->name }}</td>
                                    <td class="font-mono text-[12px]">{{ $log->action }}</td>
                                    <td class="pr-5 text-[12px] text-muted">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="py-12 text-center text-muted">Belum ada aktivitas.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($logs->hasPages())<div class="border-t border-line p-4">{{ $logs->links() }}</div>@endif
                </section>
                @break
        @endswitch
    </div>

    {{-- Formulir --}}
    @if ($form === 'clinic')
        <x-modal title="Profil klinik">
            <form wire:submit="saveClinic" class="space-y-4">
                <div><label class="label">Nama klinik</label><input type="text" wire:model="data.name" class="input"><x-error for="data.name" /></div>
                <div><label class="label">Telepon</label><input type="text" wire:model="data.phone" class="input"></div>
                <div><label class="label">Dokter penanggung jawab</label><input type="text" wire:model="data.responsible_doctor" class="input"></div>
                <div><label class="label">Tarif bagi hasil global (%)</label><input type="number" step="0.01" wire:model="data.share_percent_global" class="input"><x-error for="data.share_percent_global" /></div>
                <div class="flex justify-end gap-2"><button type="button" wire:click="closeForm" class="btn-ghost">Batal</button><button class="btn-primary">Simpan</button></div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'branch')
        <x-modal title="Ubah cabang">
            <form wire:submit="saveBranch" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label">Nama cabang</label><input type="text" wire:model="data.name" class="input"><x-error for="data.name" /></div>
                <div class="sm:col-span-2"><label class="label">Alamat</label><textarea wire:model="data.address" rows="2" class="input py-2.5"></textarea></div>
                <div><label class="label">Telepon</label><input type="text" wire:model="data.phone" class="input"></div>
                <div><label class="label">WhatsApp</label><input type="tel" wire:model="data.whatsapp" class="input" placeholder="Tampil di kwitansi"><x-error for="data.whatsapp" /></div>
                <div class="sm:col-span-2 rounded-xl border border-line p-3" x-data="{ msg: '' }">
                    <p class="text-sm font-semibold">Lokasi absensi</p>
                    <p class="mt-0.5 text-[11px] text-muted">Titik klinik untuk check in/clock out. Radius &amp; mode uji diatur di Pengaturan › Absensi. Isi dengan berdiri di klinik lalu tekan tombol di bawah.</p>
                    <div class="mt-3 grid gap-3 sm:grid-cols-2">
                        <div><label class="label">Latitude</label><input type="text" inputmode="decimal" wire:model="data.latitude" class="input" placeholder="-7.8671"><x-error for="data.latitude" /></div>
                        <div><label class="label">Longitude</label><input type="text" inputmode="decimal" wire:model="data.longitude" class="input" placeholder="112.5239"><x-error for="data.longitude" /></div>
                    </div>
                    <button type="button" class="btn-soft mt-3 min-h-9 px-3 text-xs"
                        x-on:click="if (! navigator.geolocation) { msg = 'Browser tidak mendukung lokasi.'; return }
                            msg = 'Mengambil lokasi…';
                            navigator.geolocation.getCurrentPosition(
                                p => { $wire.set('data.latitude', p.coords.latitude.toFixed(7)); $wire.set('data.longitude', p.coords.longitude.toFixed(7)); msg = 'Lokasi terisi (akurasi ±' + Math.round(p.coords.accuracy) + ' m).' },
                                e => { msg = 'Gagal mengambil lokasi: ' + e.message + (location.protocol !== 'https:' ? ' (lokasi butuh HTTPS)' : '') },
                                { enableHighAccuracy: true, timeout: 15000 })">Pakai lokasi saya sekarang</button>
                    <p x-show="msg" x-text="msg" class="mt-1.5 text-[11px] text-muted"></p>
                </div>
                <div class="sm:col-span-2">
                    <p class="label">Jam kerja</p>
                    <p class="mb-2 text-xs text-muted">Sesi 2 untuk jam setelah istirahat (kosongkan bila tanpa istirahat). Janji di luar jam ini hanya diberi peringatan.</p>
                    <div class="space-y-2">
                        @foreach (\App\Models\Branch::DAY_NAMES as $d => $dayName)
                            <div wire:key="h-{{ $d }}" class="rounded-xl border border-line p-2.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="w-16 text-sm font-medium">{{ $dayName }}</span>
                                    <label class="flex items-center gap-1.5 text-xs text-muted"><input type="checkbox" wire:model.live="data.hours.{{ $d }}.closed" class="accent-brand-600"> Tutup</label>
                                    @unless ($data['hours'][$d]['closed'] ?? false)
                                        <span class="flex items-center gap-1">
                                            <input type="time" wire:model="data.hours.{{ $d }}.s1_start" class="input h-9 w-[104px] px-2 text-sm" aria-label="{{ $dayName }} sesi 1 mulai">
                                            <span class="text-muted">–</span>
                                            <input type="time" wire:model="data.hours.{{ $d }}.s1_end" class="input h-9 w-[104px] px-2 text-sm" aria-label="{{ $dayName }} sesi 1 selesai">
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <span class="text-xs text-muted">&amp;</span>
                                            <input type="time" wire:model="data.hours.{{ $d }}.s2_start" class="input h-9 w-[104px] px-2 text-sm" aria-label="{{ $dayName }} sesi 2 mulai">
                                            <span class="text-muted">–</span>
                                            <input type="time" wire:model="data.hours.{{ $d }}.s2_end" class="input h-9 w-[104px] px-2 text-sm" aria-label="{{ $dayName }} sesi 2 selesai">
                                        </span>
                                    @endunless
                                </div>
                                <x-error for="data.hours.{{ $d }}.s1_end" />
                                <x-error for="data.hours.{{ $d }}.s2_end" />
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="sm:col-span-2"><label class="label">Nomor awal kwitansi</label><input type="number" min="1" wire:model="data.receipt_start_number" class="input"><x-error for="data.receipt_start_number" /></div>
                <div class="sm:col-span-2"><label class="label">Catatan bawah kwitansi</label><input type="text" wire:model="data.receipt_footer" class="input"></div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="data.is_active" class="accent-brand-600"> Cabang aktif</label>
                <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" wire:click="closeForm" class="btn-ghost">Batal</button><button class="btn-primary">Simpan</button></div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'procedure')
        <x-modal :title="$editingId ? 'Ubah tindakan' : 'Tindakan baru'">
            <form wire:submit="saveProcedure" class="grid gap-4 sm:grid-cols-2">
                <div><label class="label">Kode</label><input type="text" wire:model="data.code" class="input uppercase"><x-error for="data.code" /></div>
                <div><label class="label">Kategori</label><input type="text" wire:model="data.category" class="input" placeholder="mis. Konservasi"></div>
                <div class="sm:col-span-2"><label class="label">Nama tindakan</label><input type="text" wire:model="data.name" class="input"><x-error for="data.name" /></div>
                @foreach ($branches as $b)
                    <div><label class="label">Tarif {{ $b->shortName() }} minimal (Rp)</label><x-money model="data.prices.{{ $b->id }}" placeholder="Kosong = tidak tersedia" /><x-error for="data.prices.{{ $b->id }}" /></div>
                    <div><label class="label">Tarif {{ $b->shortName() }} maksimal (Rp) <span class="font-normal text-muted">kosong = satu harga</span></label><x-money model="data.max_prices.{{ $b->id }}" placeholder="Opsional" /><x-error for="data.max_prices.{{ $b->id }}" /></div>
                @endforeach
                <div class="sm:col-span-2"><label class="label">Bagi hasil khusus (%) <span class="font-normal text-muted">kosong = ikut dokter/global</span></label><input type="number" step="0.01" wire:model="data.share_percent" class="input"><x-error for="data.share_percent" /></div>
                @if ($isOwner)
                    <div class="sm:col-span-2 rounded-xl border border-line p-3">
                        <p class="text-[13px] font-semibold">Aturan fee bertahap <span class="font-normal text-muted">(gigi palsu, behel)</span></p>
                        <label class="label mt-2">Dasar fee ikut pembayaran tindakan</label>
                        <select wire:model="data.fee_from_procedure_id" class="input">
                            <option value="">— Tidak ada —</option>
                            @foreach (($procedureOptions ?? collect())->where('id', '!=', $editingId) as $opt)
                                <option value="{{ $opt->id }}">{{ $opt->name }}</option>
                            @endforeach
                        </select>
                        <x-error for="data.fee_from_procedure_id" />
                        <p class="mt-1 text-xs text-muted">Mis. Insersi ← Cetak gigi tiruan; Pasang behel ← Cetak behel. Pembayaran tahap awal pasien yang sama ikut dihitung saat tindakan ini lunas.</p>
                        <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="data.fee_minus_lab" class="accent-brand-600"> Potong biaya lab pasien pada tindakan ini</label>
                    </div>
                    <div class="sm:col-span-2 rounded-xl border border-line p-3">
                        <p class="text-[13px] font-semibold">Aturan harga & fee asisten</p>
                        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="data.price_locked" class="accent-brand-600"> Tarif terkunci — harga tidak bisa diubah saat pemeriksaan (mis. Analisis kasus)</label>
                        <label class="mt-2 flex items-center gap-2 text-sm"><input type="checkbox" wire:model="data.allow_lower_price" class="accent-brand-600"> Harga boleh di bawah standar dengan alasan (mis. cabut tidak selesai)</label>
                        <label class="label mt-3">Fee asisten nominal per tindakan (Rp) <span class="font-normal text-muted">kosong = ikut % fee asisten</span></label>
                        <x-money model="data.assistant_fee_amount" placeholder="mis. 8000" />
                        <x-error for="data.assistant_fee_amount" />
                    </div>
                    <div class="sm:col-span-2 rounded-xl border border-line p-3">
                        <div class="flex items-center justify-between">
                            <p class="text-[13px] font-semibold">Resep bahan per 1 tindakan</p>
                            <button type="button" wire:click="addMaterialRow" class="text-xs font-medium text-brand-600 hover:underline">+ Bahan</button>
                        </div>
                        <p class="mt-1 text-xs text-muted">Takaran boleh pecahan, mis. 1 tube komposit untuk 20 pasien = 0,05. Biaya bahan dipotong sebelum bagi hasil dan stok berkurang otomatis tiap genap 1 satuan. Boleh dilengkapi bertahap.</p>
                        @foreach ($data['materials'] ?? [] as $mi => $m)
                            <div wire:key="mat-{{ $mi }}" class="mt-2 flex items-start gap-2">
                                <div class="flex-1">
                                    <select wire:model="data.materials.{{ $mi }}.item_id" class="input">
                                        <option value="">— Pilih barang —</option>
                                        @foreach ($materialOptions ?? [] as $opt)
                                            <option value="{{ $opt->id }}">{{ $opt->name }} ({{ $opt->unit }})</option>
                                        @endforeach
                                    </select>
                                    <x-error for="data.materials.{{ $mi }}.item_id" />
                                </div>
                                <div class="w-28">
                                    <input type="number" step="0.0001" min="0" wire:model="data.materials.{{ $mi }}.quantity" class="input" placeholder="0,05" aria-label="Takaran">
                                    <x-error for="data.materials.{{ $mi }}.quantity" />
                                </div>
                                <button type="button" wire:click="removeMaterialRow({{ $mi }})" class="mt-2.5 text-muted hover:text-rose-ink" title="Hapus">✕</button>
                            </div>
                        @endforeach
                    </div>
                @endif
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="data.is_active" class="accent-brand-600"> Aktif</label>
                <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" wire:click="closeForm" class="btn-ghost">Batal</button><button class="btn-primary">Simpan</button></div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'procedure-import')
        <x-modal title="Impor tindakan dari Excel">
            @if ($importResult)
                @php $s = $importResult['stats']; @endphp
                <div class="rounded-xl bg-leaf-50 p-4 text-sm text-brand-800">
                    <p class="font-semibold">Impor selesai.</p>
                    <ul class="mt-2 space-y-1">
                        <li>Tindakan baru: <b>{{ $s['baru'] }}</b></li>
                        <li>Tarif diperbarui (tindakan sudah ada): {{ $s['diperbarui'] }}</li>
                        <li>Dilewati: {{ $s['dilewati'] }}</li>
                    </ul>
                </div>
                @if ($importResult['skipped'])
                    <ul class="mt-3 max-h-40 space-y-1 overflow-y-auto text-xs text-muted">
                        @foreach ($importResult['skipped'] as $line)<li>{{ $line }}</li>@endforeach
                    </ul>
                @endif
                <div class="mt-5 flex justify-end"><button type="button" wire:click="closeForm" class="btn-primary">Selesai</button></div>
            @else
                <form wire:submit="importProcedures" class="grid gap-4">
                    <p class="text-sm text-muted">Kolom wajib: <b>Nama Prosedur</b> (atau Nama/Tindakan) dan <b>Total Harga</b> (atau Harga/Tarif). Kolom Kode & Kategori opsional. Tindakan dengan nama yang sudah ada hanya diperbarui tarifnya; yang belum ada dibuat baru dengan kode otomatis.</p>
                    <div>
                        <label class="label">File Excel (.xlsx, .xls, .csv)</label>
                        <input type="file" wire:model="procedureFile" accept=".xlsx,.xls,.csv" class="input py-2.5">
                        <x-error for="procedureFile" />
                        <p wire:loading wire:target="procedureFile" class="mt-1.5 text-sm text-muted">Mengunggah…</p>
                    </div>
                    <div>
                        <label class="label">Terapkan tarif ke</label>
                        <select wire:model="importBranch" class="input">
                            <option value="all">Semua cabang</option>
                            @foreach ($branches as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                        </select>
                        <x-error for="importBranch" />
                    </div>
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="closeForm" class="btn-ghost">Batal</button>
                        <button class="btn-primary" wire:loading.attr="disabled" wire:target="procedureFile,importProcedures">
                            <span wire:loading.remove wire:target="importProcedures">Impor</span>
                            <span wire:loading wire:target="importProcedures">Mengimpor…</span>
                        </button>
                    </div>
                </form>
            @endif
        </x-modal>
    @endif

    @if ($form === 'user')
        <x-modal :title="$editingId ? 'Ubah pengguna' : 'Pengguna baru'">
            <form wire:submit="saveUser" class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label">Nama</label><input type="text" wire:model="data.name" class="input"><x-error for="data.name" /></div>
                <div><label class="label">Username</label><input type="text" wire:model="data.username" class="input" autocapitalize="none"><x-error for="data.username" /></div>
                <div><label class="label">Kata sandi @if ($editingId)<span class="font-normal text-muted">(kosong = tetap)</span>@endif</label><input type="password" wire:model="data.password" class="input" autocomplete="new-password"><x-error for="data.password" /></div>
                <div class="sm:col-span-2">
                    <label class="label">Peran</label>
                    <div class="flex flex-wrap gap-4 text-sm">
                        @foreach (Role::cases() as $r)
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="data.roles" value="{{ $r->value }}" class="accent-brand-600"> {{ $r->label() }}</label>
                        @endforeach
                    </div>
                    <x-error for="data.roles" />
                </div>
                <div class="sm:col-span-2">
                    <label class="label">Akses cabang <span class="font-normal text-muted">(Owner otomatis semua)</span></label>
                    <div class="flex flex-wrap gap-4 text-sm">
                        @foreach ($branches as $b)
                            <label class="flex items-center gap-2"><input type="checkbox" wire:model="data.branches" value="{{ $b->id }}" class="accent-brand-600"> {{ $b->shortName() }}</label>
                        @endforeach
                    </div>
                    <x-error for="data.branches" />
                </div>
                <label class="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" wire:model="data.is_active" class="accent-brand-600"> Akun aktif</label>
                <div class="flex justify-end gap-2 sm:col-span-2"><button type="button" wire:click="closeForm" class="btn-ghost">Batal</button><button class="btn-primary">Simpan</button></div>
            </form>
        </x-modal>
    @endif

    @if ($form === 'category')
        <x-modal :title="$editingId ? 'Ubah kategori' : 'Kategori baru'">
            <form wire:submit="saveCategory" class="space-y-4">
                <div><label class="label">Nama kategori</label><input type="text" wire:model="data.name" class="input"><x-error for="data.name" /></div>
                <div class="flex justify-between gap-2">
                    @if ($editingId)
                        @if ($canEdit)<button type="button" wire:click="deleteCategory({{ $editingId }})" wire:confirm="Hapus kategori ini?" class="btn-ghost text-rose-ink">Hapus</button>@endif
                    @else <span></span> @endif
                    <button class="btn-primary">Simpan</button>
                </div>
            </form>
        </x-modal>
    @endif
</div>
