<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\VisitStatus;
use App\Models\Branch;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Appointment;
use App\Models\Employee;
use App\Models\Item;
use App\Models\LabOrder;
use App\Services\StockService;
use App\Models\Invoice;
use App\Models\PayrollAdjustment;
use App\Models\MedicalRecord;
use App\Models\ToothConditionType;
use App\Models\ToothFinding;
use App\Models\Patient;
use App\Models\Procedure;
use App\Models\ScheduleOverride;
use App\Models\User;
use App\Models\Visit;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data ilustrasi mengikuti desain final & contoh SRS v2.
 * Semua akun memakai kata sandi: password
 */
class DatabaseSeeder extends Seeder
{
    private Clinic $clinic;

    private Branch $batu;

    private Branch $malang;

    /** @var array<string, Doctor> */
    private array $doctors = [];

    /** @var array<string, Procedure> */
    private array $procedures = [];

    private User $admin;

    public function run(): void
    {
        // Data ilustrasi (pasien fiktif, akun berkata sandi "password") tidak boleh masuk ke server asli.
        if (app()->isProduction()) {
            throw new \RuntimeException('DatabaseSeeder berisi data contoh dan tidak boleh dijalankan di production. Gunakan: php artisan db:seed --class=ProductionSeeder');
        }

        DB::transaction(function () {
            $this->seedClinic();
            (new ClinicalMasterSeeder)->run($this->clinic->id);
            $this->seedUsersAndDoctors();
            $this->seedSchedules();
            $this->seedProcedures();
            $this->seedExpenseCategories();
            $this->seedEmployees();
            $this->seedPatientsAndVisits();
            $this->seedStockAppointmentsLab();
        });
    }

    private function seedClinic(): void
    {
        $this->clinic = Clinic::create([
            'name' => 'Egao Dental Care',
            'phone' => '0341 000000',
            'responsible_doctor' => 'drg. Nadia Putri',
        ]);

        $this->batu = $this->clinic->branches()->create([
            'code' => 'BTU', 'name' => 'Cabang Batu', 'city' => 'Batu', 'color' => 'batu',
            'address' => 'Jl. Panglima Sudirman No.226, Tlekung, Junrejo, Kota Batu',
            'receipt_footer' => 'Terima kasih telah mempercayakan senyum Anda kepada Egao.',
        ]);

        $this->malang = $this->clinic->branches()->create([
            'code' => 'MLG', 'name' => 'Cabang Malang', 'city' => 'Malang', 'color' => 'malang',
            'address' => 'Kota Malang',
            'receipt_footer' => 'Terima kasih telah mempercayakan senyum Anda kepada Egao.',
        ]);

        DB::table('settings')->insert([
            ['key' => 'share_percent_global', 'value' => '20'],
            ['key' => 'session_idle_hours', 'value' => '8'],
        ]);
    }

    private function seedUsersAndDoctors(): void
    {
        $both = [$this->batu->id, $this->malang->id];

        // Tiara: pemilik klinik yang juga praktik sebagai dokter (bagi hasil & gaji seperti dokter lain).
        $tiara = User::create([
            'clinic_id' => $this->clinic->id, 'name' => 'Tiara', 'username' => 'owner',
            'password' => 'password', 'roles' => [Role::Owner->value, Role::Admin->value, Role::Dokter->value],
        ]);
        $tiara->branches()->attach($both);
        Doctor::create([
            'clinic_id' => $this->clinic->id, 'user_id' => $tiara->id, 'primary_branch_id' => $this->batu->id,
            'name' => 'drg. Tiara', 'base_salary' => 500_000,
        ]);

        $this->admin = User::create([
            'clinic_id' => $this->clinic->id, 'name' => 'Resepsionis Batu', 'username' => 'admin',
            'password' => 'password', 'roles' => [Role::Admin->value],
        ]);
        $this->admin->branches()->attach($this->batu->id);

        User::create([
            'clinic_id' => $this->clinic->id, 'name' => 'Resepsionis Malang', 'username' => 'admin.malang',
            'password' => 'password', 'roles' => [Role::Admin->value],
        ])->branches()->attach($this->malang->id);

        $doctorData = [
            'nadia' => ['drg. Nadia Putri', $this->batu, 500_000, '2021-03-01'],
            'arya' => ['drg. Arya Wijaya', $this->malang, 500_000, '2023-07-15'],
            'sinta' => ['drg. Sinta Dewi', $this->malang, 500_000, '2025-01-06'],
        ];

        foreach ($doctorData as $key => [$name, $branch, $salary, $joinedAt]) {
            $user = User::create([
                'clinic_id' => $this->clinic->id, 'name' => $name, 'username' => $key,
                'password' => 'password', 'roles' => [Role::Dokter->value],
            ]);
            $user->branches()->attach($both);

            $this->doctors[$key] = Doctor::create([
                'clinic_id' => $this->clinic->id, 'user_id' => $user->id, 'primary_branch_id' => $branch->id,
                'name' => $name, 'base_salary' => $salary, 'joined_at' => $joinedAt,
                'str_number' => 'STR-'.fake()->numerify('##########'),
                'sip_number' => 'SIP-'.fake()->numerify('########'),
            ]);
        }
    }

    /** Kisi minggu contoh SRS §3.3.3 (Senin..Sabtu). */
    private function seedSchedules(): void
    {
        $grid = [
            'nadia' => ['B', 'B', 'M', 'B', 'M', 'B'],
            'arya' => ['M', 'M', 'B', 'M', 'B', null],
            'sinta' => [null, 'B', 'M', null, 'M', 'B'],
        ];

        foreach ($grid as $key => $days) {
            foreach ($days as $i => $code) {
                if (! $code) {
                    continue;
                }

                DoctorSchedule::create([
                    'doctor_id' => $this->doctors[$key]->id,
                    'branch_id' => $code === 'B' ? $this->batu->id : $this->malang->id,
                    'day_of_week' => $i + 1,
                    'starts_at' => '10:00',
                    'ends_at' => '17:00',
                ]);
            }
        }

        // Skenario SRS: Rabu 18 Nov 2026 drg. Sinta pindah sementara ke Batu.
        ScheduleOverride::create([
            'doctor_id' => $this->doctors['sinta']->id,
            'date' => '2026-11-18',
            'type' => ScheduleOverride::TYPE_MOVE,
            'branch_id' => $this->batu->id,
            'note' => 'Malang penuh',
        ]);
    }

    private function seedProcedures(): void
    {
        $list = [
            ['KONS', 'Konsultasi', 'Umum', 50_000],
            ['PRKS', 'Pemeriksaan rutin', 'Umum', 100_000],
            ['SCAL', 'Scaling', 'Periodonti', 250_000],
            ['TMBL', 'Tambal gigi', 'Konservasi', 300_000],
            ['CBUT', 'Cabut gigi', 'Bedah mulut', 350_000],
            ['CBTA', 'Cabut gigi anak', 'Pedodonti', 150_000],
            ['BHEL', 'Pasang behel', 'Ortodonti', 5_000_000],
            ['KBHL', 'Kontrol behel', 'Ortodonti', 200_000],
            ['GTSL', 'Gigi tiruan lepasan', 'Prostodonti', 1_500_000],
        ];

        foreach ($list as [$code, $name, $category, $price]) {
            $procedure = Procedure::create([
                'clinic_id' => $this->clinic->id, 'code' => $code, 'name' => $name, 'category' => $category,
            ]);
            $procedure->prices()->createMany([
                ['branch_id' => $this->batu->id, 'price' => $price],
                ['branch_id' => $this->malang->id, 'price' => $price],
            ]);
            $this->procedures[$name] = $procedure;
        }
    }

    private function seedExpenseCategories(): void
    {
        $categories = collect(['Listrik dan air', 'Sewa', 'Bahan habis pakai', 'Lab gigi', 'Lain-lain'])
            ->mapWithKeys(fn ($name) => [$name => ExpenseCategory::create(['clinic_id' => $this->clinic->id, 'name' => $name])]);

        ExpenseCategory::create(['clinic_id' => $this->clinic->id, 'name' => 'Gaji dan bagi hasil', 'is_system' => true]);

        $today = today();
        Expense::create(['branch_id' => $this->batu->id, 'expense_category_id' => $categories['Bahan habis pakai']->id, 'date' => $today, 'amount' => 400_000, 'description' => 'Bahan tambal & sarung tangan']);
        Expense::create(['branch_id' => $this->malang->id, 'expense_category_id' => $categories['Lain-lain']->id, 'date' => $today, 'amount' => 250_000, 'description' => 'Perlengkapan kebersihan']);
        Expense::create(['branch_id' => $this->batu->id, 'expense_category_id' => $categories['Listrik dan air']->id, 'date' => $today->copy()->startOfMonth(), 'amount' => 1_200_000, 'description' => 'Tagihan listrik & air']);
        Expense::create(['branch_id' => $this->malang->id, 'expense_category_id' => $categories['Sewa']->id, 'date' => $today->copy()->startOfMonth(), 'amount' => 2_500_000, 'description' => 'Sewa ruko bulanan']);
        Expense::create(['branch_id' => $this->malang->id, 'expense_category_id' => $categories['Listrik dan air']->id, 'date' => $today->copy()->startOfMonth(), 'amount' => 950_000, 'description' => 'Tagihan listrik & air']);
        Expense::create(['branch_id' => $this->batu->id, 'expense_category_id' => $categories['Lab gigi']->id, 'date' => $today->copy()->subDays(3), 'amount' => 650_000, 'description' => 'Lab: retainer Hawley — pasien Batu']);
        Expense::create(['branch_id' => $this->malang->id, 'expense_category_id' => $categories['Lab gigi']->id, 'date' => $today->copy()->subDays(1), 'amount' => 1_100_000, 'description' => 'Lab: gigi tiruan lepasan akrilik — pasien Malang']);
    }

    /** Stok obat/bahan/alat, janji temu, dan order lab (permintaan klien poin 1, 5, 7, 8). */
    private function seedStockAppointmentsLab(): void
    {
        $catalog = [
            ['AMX500', 'Amoksisilin 500 mg', 'obat', 'tablet', 700, 2_000, 30],
            ['ASM500', 'Asam mefenamat 500 mg', 'obat', 'tablet', 400, 1_500, 30],
            ['PCT500', 'Parasetamol 500 mg', 'obat', 'tablet', 250, 1_000, 30],
            ['CHX', 'Obat kumur klorheksidin 150 ml', 'obat', 'botol', 22_000, 35_000, 5],
            ['SKG', 'Sikat gigi ortho', 'alat', 'pcs', 9_000, 20_000, 10],
            ['WAX', 'Wax ortho', 'alat', 'pcs', 6_000, 15_000, 10],
            ['GLV', 'Sarung tangan lateks (box)', 'bahan', 'box', 65_000, null, 3],
            ['CMP', 'Resin komposit A2', 'bahan', 'tube', 180_000, null, 2],
        ];

        $items = collect($catalog)->mapWithKeys(fn ($c) => [$c[0] => Item::create([
            'clinic_id' => $this->clinic->id, 'code' => $c[0], 'name' => $c[1], 'category' => $c[2], 'unit' => $c[3],
            'buy_price' => $c[4], 'sell_price' => $c[5], 'min_stock' => $c[6],
        ])]);

        $stock = app(StockService::class);
        foreach ([[$this->batu, 1], [$this->malang, 0.6]] as [$branch, $f]) {
            $stock->purchase($this->clinic->id, $branch->id, today()->subDays(5), 'PT Dental Supply', 'FKT-'.$branch->code.'-001',
                $items->map(fn (Item $i) => ['item_id' => $i->id, 'quantity' => (int) max(1, round(($i->min_stock * 3) * $f)), 'unit_cost' => (int) $i->buy_price])->values()->all());
        }
        $stock->move($items['GLV']->id, $this->batu->id, -2, 'pakai', ['note' => 'Pemakaian minggu ini']);

        $rina = Patient::where('medical_record_number', 128)->first();
        $ahmad = Patient::where('medical_record_number', 87)->first();
        $budi = Patient::where('medical_record_number', 132)->first();
        $siti = Patient::where('medical_record_number', 104)->first();

        // Janji temu: kontrol & janji baru beberapa hari ke depan.
        foreach ([
            [$rina, $this->batu, 'nadia', 2, '10:00', 'Pasang mahkota gigi 46', 'manual'],
            [$budi, $this->batu, null, 3, '11:00', 'Kontrol pasca cabut', 'kontrol'],
            [$siti, $this->malang, 'arya', 1, '13:00', 'Perawatan saluran akar lanjutan', 'manual'],
            [$ahmad, $this->batu, 'nadia', 0, '15:00', 'Konsultasi pemutihan', 'manual'],
        ] as [$patient, $branch, $doc, $days, $time, $purpose, $source]) {
            Appointment::create([
                'clinic_id' => $this->clinic->id, 'patient_id' => $patient->id, 'branch_id' => $branch->id,
                'doctor_id' => $doc ? $this->doctors[$doc]->id : null, 'date' => today()->addDays($days)->toDateString(),
                'time' => $time, 'purpose' => $purpose, 'source' => $source, 'created_by' => $this->admin->id,
            ]);
        }

        // Order lab gigi.
        LabOrder::create([
            'clinic_id' => $this->clinic->id, 'branch_id' => $this->batu->id, 'patient_id' => $rina->id, 'doctor_id' => $this->doctors['nadia']->id,
            'lab_name' => 'Lab Sinar Dental', 'work' => 'Mahkota PFM', 'tooth' => '46', 'sent_at' => today()->subDays(6),
            'due_at' => today()->addDay(), 'cost' => 650_000, 'status' => 'dikirim', 'created_by' => $this->admin->id,
        ]);
        LabOrder::create([
            'clinic_id' => $this->clinic->id, 'branch_id' => $this->malang->id, 'patient_id' => $siti->id, 'doctor_id' => $this->doctors['arya']->id,
            'lab_name' => 'Lab Prima Malang', 'work' => 'Gigi tiruan lepasan akrilik', 'tooth' => '36, 37', 'sent_at' => today()->subDays(10),
            'due_at' => today()->subDays(2), 'received_at' => today()->subDay(), 'cost' => 1_100_000, 'status' => 'diterima', 'created_by' => $this->admin->id,
        ]);
    }

    /** Karyawan non-dokter (FR-GJI-05) + contoh bonus bulan berjalan. */
    private function seedEmployees(): void
    {
        $users = User::whereIn('username', ['admin', 'admin.malang'])->pluck('id', 'username');

        $rows = [
            ['Resepsionis Batu', 'Admin', $this->batu, 2_500_000, $users['admin'] ?? null],
            ['Resepsionis Malang', 'Admin', $this->malang, 2_500_000, $users['admin.malang'] ?? null],
            ['Dewi Anggraini', 'Perawat', $this->batu, 2_800_000, null],
            ['Putri Rahayu', 'Asisten dokter', $this->malang, 2_300_000, null],
        ];

        foreach ($rows as [$name, $position, $branch, $salary, $userId]) {
            Employee::create([
                'clinic_id' => $this->clinic->id, 'user_id' => $userId, 'primary_branch_id' => $branch->id,
                'name' => $name, 'position' => $position, 'base_salary' => $salary, 'joined_at' => '2025-01-06',
            ]);
        }

        PayrollAdjustment::create([
            'clinic_id' => $this->clinic->id, 'period' => today()->startOfMonth(),
            'payee_type' => PayrollAdjustment::PAYEE_EMPLOYEE,
            'payee_id' => Employee::where('name', 'Dewi Anggraini')->value('id'),
            'type' => PayrollAdjustment::TYPE_BONUS, 'amount' => 200_000, 'note' => 'Lembur akhir pekan',
        ]);
    }

    private function seedPatientsAndVisits(): void
    {
        $named = [
            'rina' => [128, 'Rina Kusuma', 'P', 29, '081234562741', 'Penisilin'],
            'ahmad' => [87, 'Ahmad Fauzi', 'L', 34, '081398761234', 'Tidak ada'],
            'siti' => [104, 'Siti Aminah', 'P', 41, '085645673310', 'Tidak ada'],
            'budi' => [132, 'Budi Santoso', 'L', 52, '082233445566', 'Tidak ada'],
            'dewi' => [65, 'Dewi Lestari', 'P', 37, '087812349876', 'Tidak ada'],
        ];

        $p = [];
        foreach ($named as $key => [$rm, $name, $gender, $age, $phone, $allergy]) {
            $p[$key] = $this->patient($rm, $name, $gender, $age, $phone, $allergy);
        }

        // Pasien tambahan (RM yang belum terpakai), sebagian dengan alergi.
        $used = array_column($named, 0);
        $extra = collect();
        for ($rm = 1; $extra->count() < 40; $rm += 3) {
            if (in_array($rm, $used, true)) {
                continue;
            }
            $gender = fake()->randomElement(['L', 'P']);
            $extra->push($this->patient(
                $rm,
                fake()->firstName($gender === 'L' ? 'male' : 'female').' '.fake()->lastName(),
                $gender,
                fake()->numberBetween(5, 70),
                '08'.fake()->numerify('##########'),
                fake()->boolean(12) ? fake()->randomElement(['Penisilin', 'Lidokain', 'Lateks']) : 'Tidak ada',
            ));
        }

        $today = today();

        // Riwayat lintas cabang sesuai contoh SRS.
        $rinaOld = $this->paidVisit($p['rina'], 'arya', $this->malang, Carbon::parse('2026-10-05'), ['Konsultasi', 'Tambal gigi'], 'Konsultasi dan evaluasi tambalan');
        $this->clinicalNote($rinaOld, 'Tambalan gigi 36 terasa ngilu.', 'Evaluasi tambalan gigi 36, tambal ulang permukaan oklusal. Kontrol 6 bulan.', ['K02.1'], [[36, 'TMB', ['O']]]);
        $ahmadOld = $this->paidVisit($p['ahmad'], 'arya', $this->malang, Carbon::parse('2026-05-18'), ['Pemeriksaan rutin'], 'Pemeriksaan rutin');
        $this->clinicalNote($ahmadOld, 'Pemeriksaan rutin.', 'Edukasi menyikat gigi dan kontrol berkala.', ['Z01.2']);

        // Omzet 6 hari ke belakang (untuk diagram 7 hari).
        foreach (range(6, 1) as $back) {
            $date = $today->copy()->subDays($back);
            foreach ([$this->batu, $this->malang] as $branch) {
                $count = fake()->numberBetween(2, 4);
                for ($i = 0; $i < $count; $i++) {
                    $this->paidVisit(
                        $extra->random(),
                        $this->doctorKeyAt($branch, $date),
                        $branch,
                        $date,
                        fake()->randomElement([['Konsultasi', 'Scaling'], ['Tambal gigi'], ['Cabut gigi'], ['Pemeriksaan rutin'], ['Konsultasi', 'Tambal gigi']]),
                    );
                }
            }
        }

        // Antrean hari ini — Batu (sesuai layar dashboard desain).
        $doc = $this->doctorKeyAt($this->batu, $today);
        $this->queue($p['rina'], $doc, $this->batu, $today, '10:00', 'Tambal gigi', 'Kontrol tambalan', VisitStatus::Menunggu);
        $ahmad = $this->queue($p['ahmad'], $doc, $this->batu, $today, '10:30', 'Scaling', 'Karang gigi, gusi berdarah', VisitStatus::Diperiksa);
        $this->examDraft($ahmad); // layar "Pemeriksaan Ahmad Fauzi" pada desain
        $this->queue($p['siti'], $doc, $this->batu, $today, '11:00', 'Konsultasi', 'Sakit gigi geraham', VisitStatus::Menunggu);
        $this->queue($p['budi'], $doc, $this->batu, $today, '11:30', 'Cabut gigi', 'Pemeriksaan rutin', VisitStatus::Menunggu);
        $dewi = $this->queue($p['dewi'], $doc, $this->batu, $today, '10:00', 'Tambal gigi', 'Tambal gigi', VisitStatus::MenungguPembayaran);
        $this->invoiceFor($dewi, ['Tambal gigi'], paid: false);

        // Sudah selesai lebih awal (nomor 06–08).
        $this->paidVisit($extra[0], $doc, $this->batu, $today, ['Konsultasi', 'Scaling'], 'Scaling', '08:30');   // 300.000
        $this->paidVisit($extra[1], $doc, $this->batu, $today, ['Tambal gigi', 'Konsultasi'], 'Tambal gigi', '09:00'); // 350.000
        $this->paidVisit($extra[2], $doc, $this->batu, $today, ['Pemeriksaan rutin', 'Tambal gigi', 'Pemeriksaan rutin'], 'Kontrol', '09:30'); // 500.000 → 1.150.000

        // Antrean hari ini — Malang.
        $docM = $this->doctorKeyAt($this->malang, $today);
        $this->paidVisit($extra[3], $docM, $this->malang, $today, ['Konsultasi', 'Scaling'], 'Scaling', '09:00');
        $this->paidVisit($extra[4], $docM, $this->malang, $today, ['Cabut gigi', 'Konsultasi'], 'Cabut gigi', '09:30');
        $this->queue($extra[5], $docM, $this->malang, $today, '10:30', 'Konsultasi', 'Gigi sensitif', VisitStatus::Menunggu);
        $this->queue($extra[6], $docM, $this->malang, $today, '11:00', 'Kontrol behel', 'Kontrol behel', VisitStatus::Dipanggil);
    }

    private function patient(int $rm, string $name, string $gender, int $age, string $phone, string $allergy): Patient
    {
        return Patient::create([
            'clinic_id' => $this->clinic->id,
            'medical_record_number' => $rm,
            'name' => $name,
            'gender' => $gender,
            'birth_date' => today()->subYears($age)->subDays(fake()->numberBetween(0, 300)),
            'phone' => $phone,
            'nik' => fake()->boolean(60) ? fake()->numerify('35##############') : null,
            'address' => fake()->address(),
            'allergies' => $allergy,
            'registration_paid' => true, // data contoh = pasien lama
        ]);
    }

    /** Dokter yang bertugas di cabang pada tanggal tsb (fallback: dokter utama cabang). */
    private function doctorKeyAt(Branch $branch, CarbonInterface $date): string
    {
        foreach ($this->doctors as $key => $doctor) {
            $doctor->loadMissing('schedules', 'overrides');
            if (($doctor->scheduleOn($date)['branch_id'] ?? null) === $branch->id) {
                return $key;
            }
        }

        return $branch->is($this->batu) ? 'nadia' : 'arya';
    }

    private function queue(Patient $patient, string $doctorKey, Branch $branch, CarbonInterface $date, string $time, string $purpose, string $complaint, VisitStatus $status): Visit
    {
        $doctor = $this->doctors[$doctorKey];
        $at = $date->copy()->setTimeFromTimeString($time);

        return Visit::create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'registered_by' => $this->admin->id,
            'visit_date' => $date->toDateString(),
            'queue_number' => Visit::nextQueueNumber($branch->id, $doctor->id, $date->toDateString()),
            'scheduled_at' => $time,
            'purpose' => $purpose,
            'complaint' => $complaint,
            'status' => $status,
            'called_at' => in_array($status, [VisitStatus::Dipanggil, VisitStatus::Diperiksa, VisitStatus::MenungguPembayaran, VisitStatus::Selesai], true) ? $at : null,
            'examined_at' => in_array($status, [VisitStatus::Diperiksa, VisitStatus::MenungguPembayaran, VisitStatus::Selesai], true) ? $at->copy()->addMinutes(5) : null,
            'sent_to_cashier_at' => in_array($status, [VisitStatus::MenungguPembayaran, VisitStatus::Selesai], true) ? $at->copy()->addMinutes(30) : null,
            'completed_at' => $status === VisitStatus::Selesai ? $at->copy()->addMinutes(40) : null,
            'created_at' => $at->copy()->subMinutes(15),
            'updated_at' => $at,
        ]);
    }

    /** @param list<string> $procedureNames */
    private function paidVisit(Patient $patient, string $doctorKey, Branch $branch, CarbonInterface $date, array $procedureNames, ?string $purpose = null, string $time = '10:00'): Visit
    {
        $visit = $this->queue($patient, $doctorKey, $branch, $date, $time, $purpose ?? $procedureNames[0], $purpose ?? $procedureNames[0], VisitStatus::Selesai);
        $this->invoiceFor($visit, $procedureNames, paid: true);
        $this->clinicalNote($visit, $visit->complaint, 'Tindakan '.mb_strtolower(implode(' dan ', array_unique($procedureNames))).' selesai. Kontrol berkala.');

        return $visit;
    }

    /**
     * Rekam medis final untuk kunjungan yang sudah selesai.
     *
     * @param  list<string>  $icd10
     * @param  list<array{0:int,1:string,2:list<string>}>  $findings  [gigi, kode kondisi, permukaan]
     */
    private function clinicalNote(Visit $visit, ?string $complaint, string $plan, array $icd10 = [], array $findings = []): MedicalRecord
    {
        $at = $visit->completed_at ?? $visit->visit_date->copy()->setTime(12, 0);

        $record = MedicalRecord::updateOrCreate(['visit_id' => $visit->id], [
            'patient_id' => $visit->patient_id, 'doctor_id' => $visit->doctor_id, 'branch_id' => $visit->branch_id,
            'complaint' => $complaint, 'plan' => $plan, 'allergy_confirmed' => true,
            'status' => MedicalRecord::STATUS_FINAL, 'version' => 1, 'finalized_at' => $at,
        ]);

        foreach ($icd10 as $i => $code) {
            $visit->diagnoses()->create(['icd10_code' => $code, 'is_primary' => $i === 0]);
        }

        $this->findings($visit, $findings);

        if (! $record->versions()->exists()) {
            $record->versions()->create([
                'version' => 1,
                'user_id' => Doctor::whereKey($visit->doctor_id)->value('user_id'),
                'reason' => 'Dikirim ke kasir',
                'snapshot' => ['complaint' => $complaint, 'plan' => $plan, 'diagnoses' => $icd10],
            ]);
        }

        return $record;
    }

    /** @param list<array{0:int,1:string,2:list<string>}> $findings */
    private function findings(Visit $visit, array $findings): void
    {
        foreach ($findings as [$tooth, $code, $surfaces]) {
            ToothFinding::create([
                'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'tooth' => $tooth, 'surfaces' => $surfaces,
                'tooth_condition_type_id' => ToothConditionType::where('clinic_id', $this->clinic->id)->where('code', $code)->value('id'),
            ]);
        }
    }

    /** Pemeriksaan yang sedang berjalan (draf), persis seperti contoh SRS §3.6.6. */
    private function examDraft(Visit $visit): void
    {
        MedicalRecord::create([
            'visit_id' => $visit->id, 'patient_id' => $visit->patient_id, 'doctor_id' => $visit->doctor_id, 'branch_id' => $visit->branch_id,
            'complaint' => 'Karang gigi dan gusi berdarah saat menyikat gigi.',
            'plan' => 'Scaling dan edukasi kebersihan mulut. Evaluasi 6 bulan.',
            'follow_up_date' => $visit->visit_date->copy()->addMonths(6),
            'follow_up_label' => 'Evaluasi 6 bulan',
        ]);

        $visit->diagnoses()->create(['icd10_code' => 'K03.6', 'is_primary' => true]);
        $this->findings($visit, [[31, 'KAL', ['L']], [41, 'KAL', ['L']]]);
        $this->invoiceFor($visit, ['Scaling', 'Konsultasi'], paid: false, draft: true);
    }

    /** @param list<string> $procedureNames */
    private function invoiceFor(Visit $visit, array $procedureNames, bool $paid, bool $draft = false): Invoice
    {
        $items = collect($procedureNames)->map(function (string $name) use ($visit) {
            $procedure = $this->procedures[$name];
            $price = $procedure->prices()->where('branch_id', $visit->branch_id)->value('price');

            return [
                'procedure_id' => $procedure->id, 'doctor_id' => $visit->doctor_id, 'name' => $name,
                'quantity' => 1, 'unit_price' => $price, 'amount' => $price,
            ];
        });

        $total = $items->sum('amount');
        $paidAt = $visit->completed_at ?? $visit->visit_date->copy()->setTime(12, 0);

        $invoice = Invoice::create([
            'visit_id' => $visit->id, 'branch_id' => $visit->branch_id,
            'subtotal' => $total, 'total' => $total, 'paid' => $paid ? $total : 0,
            'status' => $paid ? Invoice::STATUS_PAID : ($draft ? Invoice::STATUS_DRAFT : Invoice::STATUS_UNPAID),
            'paid_at' => $paid ? $paidAt : null,
        ]);
        $invoice->items()->createMany($items->all());

        if ($paid) {
            $invoice->payments()->create([
                'received_by' => $this->admin->id,
                'method' => fake()->randomElement(['tunai', 'qris', 'transfer']),
                'amount' => $total,
                'paid_at' => $paidAt,
            ]);
        }

        return $invoice;
    }
}
