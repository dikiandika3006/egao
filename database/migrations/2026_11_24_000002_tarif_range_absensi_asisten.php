<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Requirement Owner 24 Nov 2026 (lanjutan). Hanya menambah kolom/tabel; data lama tidak berubah.
 * - procedure_prices.max_price: tarif range (price = minimal/standar, max_price null = satu harga).
 * - invoice_items.is_incomplete: centang "tindakan tidak selesai" (boleh di bawah tarif minimal dengan alasan).
 * - branches: koordinat + radius untuk absensi berbasis lokasi.
 * - employees: tarif shift pagi/sore per asisten (null = pakai setting global).
 * - employee_schedules: jadwal rutin asisten (seperti doctor_schedules).
 * - doctor_shift_attendances: data absen lokasi (masuk/pulang) — tetap satu sumber kehadiran dokter.
 * - employee_shift_attendances: kehadiran asisten per shift + menit lembur.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('procedure_prices', function (Blueprint $table) {
            $table->unsignedBigInteger('max_price')->nullable()->after('price');
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('is_incomplete')->default(false)->after('price_reason');
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('attendance_radius_m')->default(100)->after('longitude');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedBigInteger('morning_shift_rate')->nullable()->after('base_salary');
            $table->unsignedBigInteger('evening_shift_rate')->nullable()->after('morning_shift_rate');
        });

        Schema::create('employee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // ISO: 1 = Senin ... 7 = Minggu
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'day_of_week']);
        });

        Schema::table('doctor_shift_attendances', function (Blueprint $table) {
            $this->checkColumns($table, 'recorded_by');
        });

        Schema::create('employee_shift_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->unsignedTinyInteger('shift'); // 1 = pagi | 2 = sore
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $this->checkColumns($table);
            $table->unsignedInteger('overtime_minutes')->default(0);
            $table->timestamps();

            $table->unique(['employee_id', 'date', 'shift']);
        });

        // Tarif lembur asisten bawaan Rp5.000/jam bila belum pernah diisi Owner.
        $current = DB::table('settings')->where('key', 'assistant_overtime_rate')->value('value');
        if ($current === null || (int) $current === 0) {
            DB::table('settings')->updateOrInsert(['key' => 'assistant_overtime_rate'], ['value' => '5000']);
        }
    }

    /** Kolom absen lokasi (masuk/pulang) yang sama untuk dokter & asisten. */
    private function checkColumns(Blueprint $table, ?string $after = null): void
    {
        $cols = [
            fn () => $table->dateTime('check_in_at')->nullable(),
            fn () => $table->dateTime('check_out_at')->nullable(),
            fn () => $table->decimal('check_in_lat', 10, 7)->nullable(),
            fn () => $table->decimal('check_in_lng', 10, 7)->nullable(),
            fn () => $table->unsignedInteger('check_in_accuracy')->nullable(),
            fn () => $table->decimal('check_out_lat', 10, 7)->nullable(),
            fn () => $table->decimal('check_out_lng', 10, 7)->nullable(),
            fn () => $table->unsignedInteger('distance_m')->nullable(),
            fn () => $table->string('source', 10)->default('manual'), // manual | lokasi
            fn () => $table->string('note')->nullable(),
        ];

        foreach ($cols as $make) {
            $col = $make();
            if ($after) {
                $col->after($after);
                $after = $col->get('name');
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_attendances');
        Schema::table('doctor_shift_attendances', fn (Blueprint $t) => $t->dropColumn([
            'check_in_at', 'check_out_at', 'check_in_lat', 'check_in_lng', 'check_in_accuracy', 'check_out_lat', 'check_out_lng', 'distance_m', 'source', 'note',
        ]));
        Schema::dropIfExists('employee_schedules');
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['morning_shift_rate', 'evening_shift_rate']));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude', 'attendance_radius_m']));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn('is_incomplete'));
        Schema::table('procedure_prices', fn (Blueprint $t) => $t->dropColumn('max_price'));
    }
};
