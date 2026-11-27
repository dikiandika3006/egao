<?php

namespace App\Livewire\Modules;

use App\Enums\Role;
use App\Livewire\Concerns\ModulePage;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorProcedureShare;
use App\Models\ExpenseCategory;
use App\Models\Item;
use App\Models\Procedure;
use App\Models\User;
use App\Services\ProcedureImportService;
use App\Support\Access;
use App\Support\BranchContext;
use App\Support\Navigation;
use App\Support\Settings;
use App\Models\Payment;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Pengaturan (FR-SET-01 … 10): profil klinik & cabang (master dokter ada di modul Data dokter),
 * pengaturan aplikasi (Owner), tindakan & tarif, pengguna & peran, kategori pengeluaran, jejak audit.
 */
class Pengaturan extends Component
{
    use ModulePage, WithFileUploads, WithPagination;

    public const MENU = 'pengaturan';

    /** Boleh tanpa izin Edit (lihat ModulePage). */
    public const READ_ACTIONS = ['setTab', 'closeForm'];

    /** Aksi per menu pintasan Master data; aksi lain = pengaturan program (khusus Owner). */
    private const ACTION_MENUS = [
        'editProcedure' => 'tindakan', 'saveProcedure' => 'tindakan', 'openProcedureImport' => 'tindakan', 'importProcedures' => 'tindakan',
        'editCategory' => 'kategori-pengeluaran', 'saveCategory' => 'kategori-pengeluaran', 'deleteCategory' => 'kategori-pengeluaran',
        'saveShares' => 'tarif-gaji', 'addSpecial' => 'tarif-gaji', 'removeSpecial' => 'tarif-gaji',
        'editUser' => 'master-user', 'saveUser' => 'master-user',
    ];

    /** Matriks tab Hak akses peran: [role][menu] => ['lihat' => bool, 'edit' => bool, 'keuangan' => bool]. */
    public array $access = [];

    protected function menuKey(?string $method = null): string
    {
        if ($method !== null) {
            return self::ACTION_MENUS[$method] ?? 'pengaturan';
        }

        return self::MASTER_TABS[$this->tab] ?? 'pengaturan';
    }

    /** Formulir tab Aplikasi (khusus Owner). */
    public array $app = [];

    /** Formulir tab Absensi (khusus Owner): radius, batas akurasi GPS, mode uji. */
    public array $attendance = [];

    public $logo = null;

    public ?string $maintenanceLog = null;

    /** Formulir tab Bagi hasil: ['global' => '20', 'doctors' => [id => [...]], 'procedures' => [id => '25']]. */
    public array $shares = [];

    public array $special = ['doctor_id' => null, 'procedure_id' => null, 'share_percent' => null];

    /** Kosong = tab pertama yang tampil (Owner: Klinik & cabang, Admin: Tindakan & tarif). */
    #[Url(as: 'tab', except: '')]
    public string $tab = '';

    /**
     * Tab data master (tab => key pintasan di Navigation): dibuka dari menu Master data, tidak tampil di deretan
     * tab Pengaturan. Pengaturan sendiri hanya berisi pengaturan program.
     */
    private const MASTER_TABS = ['tindakan' => 'tindakan', 'kategori' => 'kategori-pengeluaran', 'bagi-hasil' => 'tarif-gaji', 'pengguna' => 'master-user'];

    /** null | clinic | branch | procedure | user | category */
    public ?string $form = null;

    public ?int $editingId = null;

    public array $data = [];

    /** Impor tindakan dari Excel: file, cabang tujuan ('all' atau id cabang), dan hasil. */
    public $procedureFile = null;

    public string $importBranch = 'all';

    public array $importResult = [];

    /** Pencarian daftar tindakan (kode, nama, kategori). */
    #[Url(as: 'q', except: '')]
    public string $procedureSearch = '';

    /** Tab yang tersedia: tab program khusus Owner; tab master sesuai izin Lihat menu pintasannya (Hak akses peran). */
    private function tabs(BranchContext $context): array
    {
        $tabs = $context->is(Role::Owner)
            ? ['klinik' => 'Klinik & cabang', 'aplikasi' => 'Aplikasi', 'bagi-hasil' => 'Gaji & bagi hasil', 'absensi' => 'Absensi', 'tindakan' => 'Tindakan & tarif', 'kategori' => 'Kategori pengeluaran', 'satusehat' => 'SATUSEHAT', 'google-calendar' => 'Google Calendar', 'pengguna' => 'Pengguna & peran', 'hak-akses' => 'Hak akses peran', 'audit' => 'Jejak audit']
            : ['bagi-hasil' => 'Gaji & bagi hasil', 'tindakan' => 'Tindakan & tarif', 'kategori' => 'Kategori pengeluaran'];

        return array_filter($tabs, fn ($k) => ! isset(self::MASTER_TABS[$k]) || Access::can(self::MASTER_TABS[$k], 'lihat'), ARRAY_FILTER_USE_KEY);
    }

    private function isOwner(): bool
    {
        return app(BranchContext::class)->is(Role::Owner);
    }

    public function setTab(string $tab): void
    {
        abort_unless(array_key_exists($tab, $this->tabs(app(BranchContext::class))), 403);
        $this->tab = $tab;
        $this->closeForm();
        $this->resetPage();
    }

    public function updatedProcedureSearch(): void
    {
        $this->resetPage();
    }

    public function closeForm(): void
    {
        $this->form = null;
        $this->editingId = null;
        $this->data = [];
        $this->resetValidation();
    }

    private function branches()
    {
        return Branch::where('clinic_id', $this->clinicId())->orderBy('id')->get();
    }

    // ---- Bagi hasil (Owner): global, per dokter, per tindakan, khusus dokter × tindakan ----

    private static function normalizePct($v): ?string
    {
        return $v === null || trim((string) $v) === '' ? '' : str_replace(',', '.', trim((string) $v));
    }

    private static function pct($v): string
    {
        return $v === null ? '' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    private function loadShares(): void
    {
        $this->shares = [
            'global' => static::pct(Settings::globalShare()),
            'doctors' => Doctor::where('clinic_id', $this->clinicId())->orderBy('name')->get()
                ->mapWithKeys(fn (Doctor $d) => [$d->id => ['base_salary' => (int) $d->base_salary, 'share_percent' => static::pct($d->share_percent)]])->all(),
            'procedures' => Procedure::where('clinic_id', $this->clinicId())->orderBy('name')->get()
                ->mapWithKeys(fn (Procedure $p) => [$p->id => static::pct($p->share_percent)])->all(),
            // Biaya modal per tindakan (alat behel, x-ray) dipotong sebelum fee dokter.
            'costs' => Procedure::where('clinic_id', $this->clinicId())->get()
                ->mapWithKeys(fn (Procedure $p) => [$p->id => (int) $p->cost_amount ?: null])->all(),
            // Komponen gaji per peran: dokter, asisten, admin.
            'roles' => collect(Settings::PAYROLL_AMOUNT_KEYS)->mapWithKeys(fn ($k) => [$k => Settings::int($k) ?: null])->all()
                + ['assistant_share_percent' => static::pct(Settings::float('assistant_share_percent')),
                    'default_margin_percent' => static::pct(Settings::float('default_margin_percent'))],
        ];
    }

    public function saveShares(): void
    {
        abort_unless(Access::can('tarif-gaji', 'edit'), 403);
        // Terima koma desimal ("12,5").
        $this->shares['global'] = static::normalizePct($this->shares['global'] ?? null);
        foreach ($this->shares['doctors'] ?? [] as $id => $row) {
            $this->shares['doctors'][$id]['share_percent'] = static::normalizePct($row['share_percent'] ?? null);
        }
        foreach ($this->shares['procedures'] ?? [] as $id => $value) {
            $this->shares['procedures'][$id] = static::normalizePct($value);
        }
        $this->shares['roles']['assistant_share_percent'] = static::normalizePct($this->shares['roles']['assistant_share_percent'] ?? null);
        $this->shares['roles']['default_margin_percent'] = static::normalizePct($this->shares['roles']['default_margin_percent'] ?? null);

        $this->validate([
            'shares.global' => ['required', 'numeric', 'between:0,100'],
            'shares.roles.assistant_share_percent' => ['nullable', 'numeric', 'between:0,100'],
            'shares.roles.default_margin_percent' => ['nullable', 'numeric', 'between:0,1000'],
            'shares.roles.*' => ['nullable', 'numeric', 'min:0', 'max:100000000000'],
            'shares.costs.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'shares.doctors.*.base_salary' => ['nullable', 'integer', 'min:0'],
            'shares.doctors.*.share_percent' => ['nullable', 'numeric', 'between:0,100'],
            'shares.procedures.*' => ['nullable', 'numeric', 'between:0,100'],
        ], attributes: [
            'shares.global' => 'tarif global', 'shares.doctors.*.share_percent' => 'bagi hasil dokter',
            'shares.doctors.*.base_salary' => 'gaji pokok', 'shares.procedures.*' => 'bagi hasil tindakan',
            'shares.roles.assistant_share_percent' => 'fee asisten', 'shares.roles.default_margin_percent' => 'margin obat', 'shares.roles.*' => 'nominal gaji', 'shares.costs.*' => 'biaya modal',
        ]);

        DB::transaction(function () {
            Settings::set('share_percent_global', static::pct($this->shares['global']));
            foreach (Settings::PAYROLL_AMOUNT_KEYS as $key) {
                Settings::set($key, (string) (int) ($this->shares['roles'][$key] ?? 0));
            }
            Settings::set('assistant_share_percent', static::pct((float) ($this->shares['roles']['assistant_share_percent'] ?: 0)));
            Settings::set('default_margin_percent', static::pct((float) ($this->shares['roles']['default_margin_percent'] ?: 0)));

            $costs = Procedure::where('clinic_id', $this->clinicId())->get()->keyBy('id');
            foreach ($this->shares['costs'] ?? [] as $id => $value) {
                $costs->get((int) $id)?->update(['cost_amount' => (int) ($value ?? 0)]);
            }

            $doctors = Doctor::where('clinic_id', $this->clinicId())->get()->keyBy('id');
            foreach ($this->shares['doctors'] ?? [] as $id => $row) {
                $doctors->get((int) $id)?->update([
                    'base_salary' => (int) ($row['base_salary'] ?? 0),
                    'share_percent' => ($row['share_percent'] ?? '') === '' ? null : $row['share_percent'],
                ]);
            }

            $procedures = Procedure::where('clinic_id', $this->clinicId())->get()->keyBy('id');
            foreach ($this->shares['procedures'] ?? [] as $id => $value) {
                $procedures->get((int) $id)?->update(['share_percent' => ($value ?? '') === '' ? null : $value]);
            }
        });

        AuditLog::record('settings.shares', null, $this->shares);
        $this->loadShares();
        $this->toast('Pengaturan gaji & bagi hasil disimpan.');
    }

    public function addSpecial(): void
    {
        abort_unless(Access::can('tarif-gaji', 'edit'), 403);
        $this->special['share_percent'] = static::normalizePct($this->special['share_percent']);

        $v = $this->validate([
            'special.doctor_id' => ['required', Rule::exists('doctors', 'id')->where('clinic_id', $this->clinicId())],
            'special.procedure_id' => ['required', Rule::exists('procedures', 'id')->where('clinic_id', $this->clinicId())],
            'special.share_percent' => ['required', 'numeric', 'between:0,100'],
        ], attributes: ['special.doctor_id' => 'dokter', 'special.procedure_id' => 'tindakan', 'special.share_percent' => 'persentase'])['special'];

        $row = DoctorProcedureShare::updateOrCreate(
            ['doctor_id' => $v['doctor_id'], 'procedure_id' => $v['procedure_id']],
            ['share_percent' => $v['share_percent']],
        );
        AuditLog::record('settings.shares.special', $row, $v);

        $this->special = ['doctor_id' => $v['doctor_id'], 'procedure_id' => null, 'share_percent' => null];
        $this->toast('Tarif khusus disimpan.');
    }

    public function removeSpecial(int $id): void
    {
        abort_unless(Access::can('tarif-gaji', 'edit'), 403);
        $row = DoctorProcedureShare::whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()))->findOrFail($id);
        AuditLog::record('settings.shares.special.deleted', $row, $row->only(['doctor_id', 'procedure_id', 'share_percent']));
        $row->delete();
    }

    // ---- Aplikasi (Owner): identitas, kwitansi, kasir, keamanan, pemeliharaan ---------

    private function loadApp(): void
    {
        $this->app = [
            'clinic_tagline' => (string) Settings::get('clinic_tagline'),
            'clinic_email' => (string) Settings::get('clinic_email'),
            'clinic_whatsapp' => (string) Settings::get('clinic_whatsapp'),
            'receipt_prefix' => (string) Settings::get('receipt_prefix'),
            'receipt_show_logo' => Settings::bool('receipt_show_logo'),
            'payment_methods' => array_keys(Settings::paymentMethods()),
            'admin_max_discount_percent' => (string) Settings::get('admin_max_discount_percent'),
            'session_idle_hours' => (string) Settings::idleHours(),
        ];
    }

    public function saveApp(): void
    {
        abort_unless($this->isOwner(), 403);

        $v = $this->validate([
            'app.clinic_tagline' => ['nullable', 'string', 'max:120'],
            'app.clinic_email' => ['nullable', 'email', 'max:120'],
            'app.clinic_whatsapp' => ['nullable', 'regex:/^[0-9+\-\s]{8,20}$/'],
            'app.receipt_prefix' => ['nullable', 'alpha', 'max:5'],
            'app.receipt_show_logo' => ['boolean'],
            'app.payment_methods' => ['required', 'array', 'min:1'],
            'app.payment_methods.*' => [Rule::in(array_keys(Payment::METHODS))],
            'app.admin_max_discount_percent' => ['required', 'integer', 'between:0,100'],
            'app.session_idle_hours' => ['required', 'integer', 'min:1', 'max:'.max(1, intdiv((int) config('session.lifetime'), 60))],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ], [
            'app.payment_methods.required' => 'Aktifkan minimal satu metode bayar.',
            'app.receipt_prefix.alpha' => 'Prefix kwitansi hanya huruf.',
            'app.clinic_whatsapp.regex' => 'Nomor WhatsApp tidak valid.',
        ], [
            'app.clinic_email' => 'email', 'app.admin_max_discount_percent' => 'batas diskon', 'app.session_idle_hours' => 'batas idle',
        ])['app'];

        Settings::set('clinic_tagline', $v['clinic_tagline'] ?: null);
        Settings::set('clinic_email', $v['clinic_email'] ?: null);
        Settings::set('clinic_whatsapp', $v['clinic_whatsapp'] ?: null);
        Settings::set('receipt_prefix', strtoupper((string) $v['receipt_prefix']));
        Settings::set('receipt_show_logo', $v['receipt_show_logo'] ? '1' : '0');
        Settings::set('payment_methods', implode(',', array_intersect(array_keys(Payment::METHODS), $v['payment_methods'])));
        Settings::set('admin_max_discount_percent', (string) (int) $v['admin_max_discount_percent']);
        Settings::set('session_idle_hours', (string) (int) $v['session_idle_hours']);

        if ($this->logo) {
            $clinic = Clinic::findOrFail($this->clinicId());
            if ($clinic->logo_path) {
                Storage::disk('public')->delete($clinic->logo_path);
            }
            $clinic->update(['logo_path' => $this->logo->store('logo', 'public')]);
            $this->logo = null;
        }

        AuditLog::record('settings.app', null, $v);
        $this->loadApp();
        $this->toast('Pengaturan aplikasi disimpan.');
    }

    private function loadAttendance(): void
    {
        $this->attendance = [
            'radius_m' => (string) Settings::int('attendance_radius_m'),
            'max_accuracy_m' => (string) Settings::int('attendance_max_accuracy_m'),
            'test_mode' => Settings::bool('attendance_test_mode'),
        ];
    }

    public function saveAttendance(): void
    {
        abort_unless($this->isOwner(), 403);
        $v = $this->validate([
            'attendance.radius_m' => ['required', 'integer', 'min:10', 'max:2000'],
            'attendance.max_accuracy_m' => ['required', 'integer', 'min:10', 'max:500'],
            'attendance.test_mode' => ['boolean'],
        ], attributes: ['attendance.radius_m' => 'radius absensi', 'attendance.max_accuracy_m' => 'batas akurasi GPS'])['attendance'];

        $before = ['radius_m' => Settings::int('attendance_radius_m'), 'max_accuracy_m' => Settings::int('attendance_max_accuracy_m'), 'test_mode' => Settings::bool('attendance_test_mode')];
        Settings::set('attendance_radius_m', (string) (int) $v['radius_m']);
        Settings::set('attendance_max_accuracy_m', (string) (int) $v['max_accuracy_m']);
        Settings::set('attendance_test_mode', $v['test_mode'] ? '1' : '0');

        AuditLog::record('settings.absensi', null, ['before' => $before, 'after' => $v]);
        $this->loadAttendance();
        $this->toast('Pengaturan absensi disimpan.');
    }

    public function removeLogo(): void
    {
        abort_unless($this->isOwner(), 403);
        $clinic = Clinic::findOrFail($this->clinicId());
        if ($clinic->logo_path) {
            Storage::disk('public')->delete($clinic->logo_path);
            $clinic->update(['logo_path' => null]);
            AuditLog::record('settings.logo.removed', $clinic);
        }
    }

    /**
     * Pemeliharaan setelah unggah versi baru (pola "Update database" Cattleya):
     * jalankan migrasi tertunda & bersihkan cache. Sengaja TANPA seeder — seeder egao berisi data ilustrasi.
     */
    public function runMaintenance(): void
    {
        abort_unless($this->isOwner(), 403);

        $log = [];
        try {
            Artisan::call('migrate', ['--force' => true]);
            $log[] = trim(Artisan::output()) ?: 'Migrasi: tidak ada perubahan.';

            if (! file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
                $log[] = trim(Artisan::output());
            }

            Artisan::call('optimize:clear');
            $log[] = 'Cache dibersihkan.';
            $this->toast('Database & cache diperbarui.');
        } catch (\Throwable $e) {
            $log[] = 'Gagal: '.$e->getMessage();
            $this->toast('Pemeliharaan gagal, lihat log.');
        }

        $this->maintenanceLog = implode("\n", array_filter($log));
        AuditLog::record('settings.maintenance', null, ['log' => mb_substr($this->maintenanceLog, 0, 2000)]);
    }

    /** Jumlah file migrasi yang belum dijalankan. */
    private function pendingMigrations(): int
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $files = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'));

        return $files->diff($ran)->count();
    }

    // ---- Klinik & cabang (FR-SET-01) -----------------------------------------------

    public function editClinic(): void
    {
        abort_unless($this->isOwner(), 403);
        $c = Clinic::findOrFail($this->clinicId());
        $this->data = $c->only(['name', 'phone', 'responsible_doctor']) + ['share_percent_global' => (string) Settings::globalShare()];
        $this->form = 'clinic';
    }

    public function saveClinic(): void
    {
        abort_unless($this->isOwner(), 403);
        $v = $this->validate([
            'data.name' => ['required', 'string', 'max:120'],
            'data.phone' => ['nullable', 'string', 'max:30'],
            'data.responsible_doctor' => ['nullable', 'string', 'max:120'],
            'data.share_percent_global' => ['required', 'numeric', 'between:0,100'],
        ], attributes: ['data.name' => 'nama klinik', 'data.share_percent_global' => 'tarif bagi hasil global'])['data'];

        $clinic = Clinic::findOrFail($this->clinicId());
        $clinic->update(collect($v)->except('share_percent_global')->all());
        Settings::set('share_percent_global', (string) (float) $v['share_percent_global']);
        AuditLog::record('settings.clinic', $clinic, $v);
        $this->closeForm();
        $this->toast('Profil klinik disimpan.');
    }

    public function editBranch(int $id): void
    {
        abort_unless($this->isOwner(), 403);
        $b = Branch::where('clinic_id', $this->clinicId())->findOrFail($id);
        $this->editingId = $b->id;
        $this->data = $b->only(['name', 'address', 'phone', 'receipt_start_number', 'receipt_footer']) + [
            'is_active' => $b->is_active,
            // Titik absensi berbasis lokasi (kosong = absen lokasi belum bisa di cabang ini).
            'latitude' => $b->latitude !== null ? (string) $b->latitude : '',
            'longitude' => $b->longitude !== null ? (string) $b->longitude : '',
            // Jam kerja per hari: tutup + sesi 1 (wajib bila buka) + sesi 2 (opsional, mis. setelah istirahat).
            'hours' => collect($b->weeklyHours())->map(fn (array $s) => [
                'closed' => $s === [],
                's1_start' => $s[0][0] ?? '', 's1_end' => $s[0][1] ?? '',
                's2_start' => $s[1][0] ?? '', 's2_end' => $s[1][1] ?? '',
            ])->all(),
        ];
        $this->form = 'branch';
    }

    public function saveBranch(): void
    {
        abort_unless($this->isOwner() && $this->editingId, 403);
        $v = $this->validate([
            'data.name' => ['required', 'string', 'max:120'],
            'data.address' => ['nullable', 'string', 'max:500'],
            'data.phone' => ['nullable', 'string', 'max:30'],
            'data.receipt_start_number' => ['required', 'integer', 'min:1'],
            'data.receipt_footer' => ['nullable', 'string', 'max:255'],
            'data.is_active' => ['boolean'],
            'data.latitude' => ['nullable', 'required_with:data.longitude', 'numeric', 'between:-90,90'],
            'data.longitude' => ['nullable', 'required_with:data.latitude', 'numeric', 'between:-180,180'],
            'data.hours' => ['required', 'array'],
            'data.hours.*.closed' => ['boolean'],
            'data.hours.*.s1_start' => ['nullable', 'date_format:H:i'],
            'data.hours.*.s1_end' => ['nullable', 'date_format:H:i'],
            'data.hours.*.s2_start' => ['nullable', 'date_format:H:i'],
            'data.hours.*.s2_end' => ['nullable', 'date_format:H:i'],
        ], attributes: ['data.receipt_start_number' => 'nomor awal kwitansi', 'data.latitude' => 'latitude', 'data.longitude' => 'longitude'])['data'];

        $v['latitude'] = ($v['latitude'] ?? '') === '' ? null : (float) $v['latitude'];
        $v['longitude'] = ($v['longitude'] ?? '') === '' ? null : (float) $v['longitude'];

        $hours = $this->parseHours($v['hours']);
        unset($v['hours']);

        // opens_at/closes_at (tampilan lama) = jam buka paling awal & tutup paling akhir dalam seminggu.
        $sessions = collect($hours)->flatten(1);
        $v['opens_at'] = $sessions->min(0) ?? '10:00';
        $v['closes_at'] = $sessions->max(1) ?? '17:00';
        $v['operating_hours'] = $hours;

        $b = Branch::where('clinic_id', $this->clinicId())->findOrFail($this->editingId);
        $b->update($v);
        AuditLog::record('settings.branch', $b, $v);
        $this->closeForm();
        $this->toast('Cabang disimpan.');
    }

    /**
     * Ubah isian form jam kerja menjadi [hari => [[mulai, selesai], …]] dan validasi urutan sesi.
     *
     * @throws ValidationException
     */
    private function parseHours(array $input): array
    {
        $hours = [];
        $errors = [];

        foreach (range(1, 7) as $day) {
            $row = $input[$day] ?? $input[(string) $day] ?? [];
            $name = Branch::DAY_NAMES[$day];
            $hours[$day] = [];

            if (! empty($row['closed'])) {
                continue;
            }

            [$s1, $e1, $s2, $e2] = [$row['s1_start'] ?? '', $row['s1_end'] ?? '', $row['s2_start'] ?? '', $row['s2_end'] ?? ''];

            if ($s1 === '' || $e1 === '' || $e1 <= $s1) {
                $errors["data.hours.{$day}.s1_end"] = "{$name}: isi jam mulai & selesai sesi 1 (selesai setelah mulai), atau centang Tutup.";

                continue;
            }
            $hours[$day][] = [$s1, $e1];

            if ($s2 !== '' || $e2 !== '') {
                if ($s2 === '' || $e2 === '' || $e2 <= $s2 || $s2 < $e1) {
                    $errors["data.hours.{$day}.s2_end"] = "{$name}: sesi 2 harus lengkap dan dimulai setelah sesi 1 selesai.";

                    continue;
                }
                $hours[$day][] = [$s2, $e2];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $hours;
    }

    // ---- Tindakan & tarif per cabang (FR-SET-03) -----------------------------------

    public function editProcedure(?int $id = null): void
    {
        $p = $id ? Procedure::where('clinic_id', $this->clinicId())->with('prices')->findOrFail($id) : null;
        $this->editingId = $p?->id;
        $this->data = [
            'code' => (string) $p?->code, 'name' => (string) $p?->name, 'category' => (string) $p?->category,
            'share_percent' => $p?->share_percent !== null ? (string) (float) $p->share_percent : '',
            'is_active' => $p->is_active ?? true,
            'fee_from_procedure_id' => (string) $p?->fee_from_procedure_id,
            'fee_minus_lab' => (bool) $p?->fee_minus_lab,
            'price_locked' => (bool) $p?->price_locked,
            'allow_lower_price' => (bool) $p?->allow_lower_price,
            'assistant_fee_amount' => $p?->assistant_fee_amount,
            // Resep bahan: takaran per 1 tindakan (boleh pecahan), diisi Owner bertahap.
            'materials' => $p ? $p->materials()->get()->map(fn ($m) => ['item_id' => (string) $m->item_id, 'quantity' => rtrim(rtrim(number_format($m->quantity, 4, '.', ''), '0'), '.')])->all() : [],
            'prices' => $this->branches()->mapWithKeys(fn ($b) => [$b->id => $p?->priceFor($b->id)])->all(),
            // Tarif range: batas atas opsional (kosong = satu harga).
            'max_prices' => $this->branches()->mapWithKeys(fn ($b) => [$b->id => $p?->rangeFor($b->id)[1] ?? null])->all(),
        ];
        $this->resetValidation();
        $this->form = 'procedure';
    }

    public function saveProcedure(): void
    {
        $v = $this->validate([
            'data.code' => ['required', 'alpha_dash', 'max:20', Rule::unique('procedures', 'code')->where('clinic_id', $this->clinicId())->ignore($this->editingId)],
            'data.name' => ['required', 'string', 'max:120'],
            'data.category' => ['nullable', 'string', 'max:60'],
            'data.share_percent' => ['nullable', 'numeric', 'between:0,100'],
            'data.is_active' => ['boolean'],
            // Aturan fee bertahap (gigi palsu/behel): tindakan tahap awal milik klinik ini, bukan dirinya sendiri.
            'data.fee_from_procedure_id' => ['nullable', Rule::exists('procedures', 'id')->where('clinic_id', $this->clinicId()), Rule::notIn(array_filter([$this->editingId]))],
            'data.fee_minus_lab' => ['boolean'],
            'data.price_locked' => ['boolean'],
            'data.allow_lower_price' => ['boolean'],
            'data.assistant_fee_amount' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'data.materials' => ['array', 'max:30'],
            'data.materials.*.item_id' => ['required', 'distinct', Rule::exists('items', 'id')->where('clinic_id', $this->clinicId())],
            'data.materials.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000'],
            'data.prices' => ['array'],
            'data.prices.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'data.max_prices' => ['array'],
            'data.max_prices.*' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
        ], attributes: ['data.code' => 'kode', 'data.name' => 'nama tindakan', 'data.prices.*' => 'tarif', 'data.max_prices.*' => 'tarif maksimal', 'data.fee_from_procedure_id' => 'dasar fee ikut tindakan',
            'data.assistant_fee_amount' => 'fee asisten nominal', 'data.materials.*.item_id' => 'bahan', 'data.materials.*.quantity' => 'takaran'])['data'];

        // Tarif range: maksimal harus lebih besar dari minimal dan hanya bila tarif minimal diisi.
        foreach ($v['max_prices'] ?? [] as $branchId => $max) {
            if ($max === null || $max === '') {
                continue;
            }
            $min = $v['prices'][$branchId] ?? null;
            if ($min === null || $min === '') {
                $this->addError("data.max_prices.{$branchId}", 'Isi tarif minimal dulu.');
            } elseif ((int) $max < (int) $min) {
                $this->addError("data.max_prices.{$branchId}", 'Tarif maksimal tidak boleh lebih kecil dari minimal.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

        // Aturan fee memengaruhi gaji dokter: hanya Owner yang boleh mengubah.
        $canSetFeeRule = $this->isOwner();

        $branchIds = $this->branches()->pluck('id');

        $p = DB::transaction(function () use ($v, $branchIds, $canSetFeeRule) {
            $p = $this->editingId ? Procedure::where('clinic_id', $this->clinicId())->findOrFail($this->editingId) : new Procedure(['clinic_id' => $this->clinicId()]);
            $p->fill([
                'code' => strtoupper($v['code']), 'name' => $v['name'], 'category' => $v['category'] ?: null,
                'share_percent' => $v['share_percent'] === '' ? null : $v['share_percent'], 'is_active' => $v['is_active'],
            ]);
            if ($canSetFeeRule) {
                $p->fill([
                    'fee_from_procedure_id' => ($v['fee_from_procedure_id'] ?? '') ?: null, 'fee_minus_lab' => (bool) ($v['fee_minus_lab'] ?? false),
                    'price_locked' => (bool) ($v['price_locked'] ?? false), 'allow_lower_price' => (bool) ($v['allow_lower_price'] ?? false),
                    'assistant_fee_amount' => ($v['assistant_fee_amount'] ?? '') === '' ? null : (int) $v['assistant_fee_amount'],
                ]);
            }
            $p->save();

            if ($canSetFeeRule) {
                $keep = collect($v['materials'] ?? [])->mapWithKeys(fn ($m) => [(int) $m['item_id'] => round((float) $m['quantity'], 4)]);
                $p->materials()->whereNotIn('item_id', $keep->keys())->delete();
                foreach ($keep as $itemId => $qty) {
                    $p->materials()->updateOrCreate(['item_id' => $itemId], ['quantity' => $qty]);
                }
            }

            foreach ($v['prices'] ?? [] as $branchId => $price) {
                if (! $branchIds->contains((int) $branchId)) {
                    continue;
                }
                if ($price === null || $price === '') {
                    $p->prices()->where('branch_id', $branchId)->delete();
                } else {
                    $max = $v['max_prices'][$branchId] ?? null;
                    $max = $max === null || $max === '' || (int) $max === (int) $price ? null : (int) $max;
                    $p->prices()->updateOrCreate(['branch_id' => $branchId], ['price' => (int) $price, 'max_price' => $max]);
                }
            }

            return $p;
        });

        // Harga lama di tagihan tetap (snapshot di invoice_items).
        AuditLog::record($this->editingId ? 'procedure.updated' : 'procedure.created', $p, $v);
        $this->closeForm();
        $this->toast('Tindakan & tarif disimpan.');
    }

    /** Baris resep bahan di formulir tindakan (disimpan bersama tindakan). */
    public function addMaterialRow(): void
    {
        abort_unless($this->isOwner() && $this->form === 'procedure', 403);
        $this->data['materials'][] = ['item_id' => '', 'quantity' => ''];
    }

    public function removeMaterialRow(int $index): void
    {
        abort_unless($this->isOwner() && $this->form === 'procedure', 403);
        unset($this->data['materials'][$index]);
        $this->data['materials'] = array_values($this->data['materials']);
    }

    private function authorizeProcedureImport(): void
    {
        abort_unless(Access::can('tindakan', 'edit'), 403);
    }

    public function openProcedureImport(): void
    {
        $this->authorizeProcedureImport();
        $this->reset('procedureFile', 'importResult', 'importBranch');
        $this->resetValidation();
        $this->form = 'procedure-import';
    }

    public function importProcedures(ProcedureImportService $service): void
    {
        $this->authorizeProcedureImport();
        $branchIds = $this->branches()->pluck('id');
        $this->validate([
            'procedureFile' => ['required', 'file', 'max:5120'],
            'importBranch' => ['required', Rule::in(['all', ...$branchIds->map(fn ($id) => (string) $id)])],
        ], attributes: ['procedureFile' => 'file Excel', 'importBranch' => 'cabang']);

        // Ekstensi dicek manual: xlsx sering terbaca sebagai application/zip oleh aturan mimes.
        $ext = strtolower($this->procedureFile->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $this->addError('procedureFile', 'File harus Excel (.xlsx, .xls) atau CSV.');

            return;
        }

        $targets = $this->importBranch === 'all' ? $branchIds->all() : [(int) $this->importBranch];

        // File unggahan bernama acak tanpa ekstensi; salin ke path berekstensi agar pembaca Excel mengenalinya.
        $local = tempnam(sys_get_temp_dir(), 'import-tindakan').'.'.$ext;
        copy($this->procedureFile->getRealPath(), $local);
        try {
            $result = $service->import($local, $this->clinicId(), $targets);
        } finally {
            @unlink($local);
        }

        if ($result['error']) {
            $this->addError('procedureFile', $result['error']);

            return;
        }

        AuditLog::record('procedure.imported', null, [
            'file' => $this->procedureFile->getClientOriginalName(), 'branch_ids' => $targets, 'stats' => $result['stats'],
        ]);
        $this->procedureFile = null;
        $this->importResult = $result;
    }

    // ---- Pengguna & peran (FR-SET-07/08) -------------------------------------------

    public function editUser(?int $id = null): void
    {
        abort_unless($this->isOwner(), 403);
        $u = $id ? User::where('clinic_id', $this->clinicId())->with('branches')->findOrFail($id) : null;
        $this->editingId = $u?->id;
        $this->data = [
            'name' => (string) $u?->name, 'username' => (string) $u?->username, 'password' => '',
            'roles' => $u->roles ?? [Role::Admin->value],
            'branches' => $u ? $u->branches->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
            'is_active' => $u->is_active ?? true,
        ];
        $this->resetValidation();
        $this->form = 'user';
    }

    public function saveUser(): void
    {
        abort_unless($this->isOwner(), 403);
        $v = $this->validate([
            'data.name' => ['required', 'string', 'max:120'],
            'data.username' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($this->editingId)],
            'data.password' => [$this->editingId ? 'nullable' : 'required', Password::min(8)],
            'data.roles' => ['required', 'array', 'min:1'],
            'data.roles.*' => [Rule::enum(Role::class)],
            'data.branches' => ['array'],
            'data.branches.*' => [Rule::exists('branches', 'id')->where('clinic_id', $this->clinicId())],
            'data.is_active' => ['boolean'],
        ], ['data.username.regex' => 'Username hanya huruf kecil, angka, titik, strip.'], [
            'data.name' => 'nama', 'data.password' => 'kata sandi', 'data.roles' => 'peran',
        ])['data'];

        $isOwner = in_array(Role::Owner->value, $v['roles'], true);
        if (! $isOwner && empty($v['branches'])) {
            $this->addError('data.branches', 'Pilih minimal satu cabang.');

            return;
        }
        if ($this->editingId === auth()->id() && (! $isOwner || ! $v['is_active'])) {
            $this->addError('data.roles', 'Tidak bisa melepas peran Owner atau menonaktifkan akun sendiri.');

            return;
        }

        $u = DB::transaction(function () use ($v) {
            $u = $this->editingId ? User::where('clinic_id', $this->clinicId())->findOrFail($this->editingId) : new User(['clinic_id' => $this->clinicId()]);
            $u->fill([
                'name' => $v['name'], 'username' => $v['username'], 'roles' => array_values($v['roles']), 'is_active' => $v['is_active'],
            ]);
            if (filled($v['password'])) {
                $u->password = $v['password'];
            }
            $u->save();
            $u->branches()->sync(array_map('intval', $v['branches']));

            return $u;
        });

        AuditLog::record($this->editingId ? 'user.updated' : 'user.created', $u, collect($v)->except('password')->all() + ['password_changed' => filled($v['password'])]);
        $this->closeForm();
        $this->toast('Pengguna disimpan.');
    }

    // ---- Kategori pengeluaran (FR-PNG-02) ------------------------------------------

    public function editCategory(?int $id = null): void
    {
        $c = $id ? ExpenseCategory::where('clinic_id', $this->clinicId())->where('is_system', false)->findOrFail($id) : null;
        $this->editingId = $c?->id;
        $this->data = ['name' => (string) $c?->name];
        $this->resetValidation();
        $this->form = 'category';
    }

    public function saveCategory(): void
    {
        $v = $this->validate([
            'data.name' => ['required', 'string', 'max:80', Rule::unique('expense_categories', 'name')->where('clinic_id', $this->clinicId())->ignore($this->editingId)],
        ], attributes: ['data.name' => 'nama kategori'])['data'];

        $c = $this->editingId
            ? ExpenseCategory::where('clinic_id', $this->clinicId())->where('is_system', false)->findOrFail($this->editingId)
            : new ExpenseCategory(['clinic_id' => $this->clinicId()]);
        $c->fill($v)->save();
        $this->closeForm();
        $this->toast('Kategori disimpan.');
    }

    public function deleteCategory(int $id): void
    {
        $c = ExpenseCategory::where('clinic_id', $this->clinicId())->where('is_system', false)->withCount('expenses')->findOrFail($id);
        if ($c->expenses_count > 0) {
            $this->toast('Kategori sudah dipakai, tidak bisa dihapus.');

            return;
        }
        $c->delete();
        $this->closeForm();
        $this->toast('Kategori dihapus.');
    }

    // ---- Hak akses peran (Owner) ------------------------------------------------------

    public function saveAccess(): void
    {
        abort_unless($this->isOwner(), 403);
        Access::save($this->access);
        $this->access = Access::matrix();
        $this->toast('Hak akses peran disimpan.');
    }

    public function resetAccess(): void
    {
        abort_unless($this->isOwner(), 403);
        Access::reset();
        $this->access = Access::matrix();
        $this->toast('Hak akses dikembalikan ke bawaan.');
    }

    public function render(BranchContext $context)
    {
        $tabs = $this->tabs($context);
        $shownTabs = array_diff_key($tabs, self::MASTER_TABS);
        if (! array_key_exists($this->tab, $tabs)) {
            // Admin tidak punya tab program → jatuh ke tab master pertama (Tindakan & tarif).
            $this->tab = array_key_first($shownTabs) ?? array_key_first($tabs) ?? '';
        }
        $masterKey = self::MASTER_TABS[$this->tab] ?? null;

        if ($this->tab === 'aplikasi' && $this->app === []) {
            $this->loadApp();
        }
        if ($this->tab === 'absensi' && $this->attendance === []) {
            $this->loadAttendance();
        }
        if ($this->tab === 'bagi-hasil' && $this->shares === []) {
            $this->loadShares();
        }
        if ($this->tab === 'hak-akses' && $this->access === []) {
            $this->access = Access::matrix();
        }

        $branches = $this->branches();
        $view = ['context' => $context, 'tabs' => $shownTabs, 'branches' => $branches, 'isOwner' => $context->is(Role::Owner)];

        $view += match ($this->tab) {
            'klinik' => ['clinic' => Clinic::findOrFail($this->clinicId()), 'globalShare' => Settings::globalShare()],
            'bagi-hasil' => [
                'shareDoctors' => Doctor::where('clinic_id', $this->clinicId())->with('primaryBranch')->orderByDesc('is_active')->orderBy('name')->get(),
                'shareProcedures' => Procedure::where('clinic_id', $this->clinicId())->orderByDesc('is_active')->orderBy('category')->orderBy('name')->get(),
                'specials' => DoctorProcedureShare::whereHas('doctor', fn ($q) => $q->where('clinic_id', $this->clinicId()))
                    ->with(['doctor', 'procedure'])->get()->sortBy(fn ($s) => $s->doctor->name.$s->procedure->name)->values(),
            ],
            'aplikasi' => ['clinic' => Clinic::findOrFail($this->clinicId()), 'methods' => Payment::METHODS, 'pendingMigrations' => $this->pendingMigrations()],
            'absensi' => ['locationBranches' => Branch::where('clinic_id', $this->clinicId())->orderBy('id')->get()],
            'tindakan' => [
                'procedures' => Procedure::where('clinic_id', $this->clinicId())
                    ->when(trim($this->procedureSearch) !== '', function ($q) {
                        $term = '%'.addcslashes(trim($this->procedureSearch), '%_\\').'%';
                        $q->where(fn ($w) => $w->where('code', 'like', $term)->orWhere('name', 'like', $term)->orWhere('category', 'like', $term));
                    })
                    ->with('prices')->orderByDesc('is_active')->orderBy('category')->orderBy('name')
                    ->paginate(25),
                // Pilihan "dasar fee ikut tindakan" di formulir butuh semua tindakan, bukan hanya halaman aktif.
                'procedureOptions' => $this->form === 'procedure'
                    ? Procedure::where('clinic_id', $this->clinicId())->orderBy('name')->get(['id', 'name'])
                    : collect(),
                'materialOptions' => $this->form === 'procedure'
                    ? Item::where('clinic_id', $this->clinicId())->where('is_active', true)->orderByRaw("category = 'bahan' desc")->orderBy('name')->get(['id', 'name', 'unit', 'category'])
                    : collect(),
            ],
            'hak-akses' => ['accessRoles' => Access::roles(), 'accessMenus' => Access::menus()],
            'pengguna' => ['users' => User::where('clinic_id', $this->clinicId())->with('branches')->orderBy('name')->get()],
            'kategori' => ['categories' => ExpenseCategory::where('clinic_id', $this->clinicId())->withCount('expenses')->orderBy('is_system')->orderBy('name')->get()],
            'audit' => ['logs' => AuditLog::query()
                ->whereHas('user', fn ($q) => $q->where('clinic_id', $this->clinicId()))
                ->with('user')
                ->latest('created_at')->latest('id')
                ->paginate(30)],
            default => [],
        };

        if ($masterKey) {
            // Tab master tampil sebagai halaman Master data sendiri: judul & breadcrumb dari pintasannya.
            $master = Navigation::item($masterKey);

            return $this->page('pengaturan', 'livewire.modules.pengaturan', $view + ['masterItem' => $master])
                ->title($master['label'])
                ->layoutData(['breadcrumb' => $master['breadcrumb'], 'active' => 'pengaturan']);
        }

        return $this->page('pengaturan', 'livewire.modules.pengaturan', $view);
    }
}
