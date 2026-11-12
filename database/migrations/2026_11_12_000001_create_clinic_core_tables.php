<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akses cabang per pengguna (FR-SET-07)
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });

        // Master dokter (FR-SET-02)
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('primary_branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('str_number')->nullable();
            $table->string('sip_number')->nullable();
            $table->unsignedBigInteger('base_salary')->default(0);
            $table->decimal('share_percent', 5, 2)->nullable(); // null = pakai tarif global
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Jadwal rutin mingguan (FR-JDW-06/07)
        Schema::create('doctor_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('day_of_week'); // ISO: 1 = Senin ... 7 = Minggu
            $table->time('starts_at');
            $table->time('ends_at');
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable(); // null = tanpa batas
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'day_of_week']);
        });

        // Pindah cabang sementara & libur (FR-JDW-08/10)
        Schema::create('schedule_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('type', 20); // move | off
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['doctor_id', 'date']);
        });

        // Pasien: satu data untuk semua cabang, TANPA branch_id (SRS §5)
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('medical_record_number');
            $table->string('name');
            $table->char('gender', 1); // L | P
            $table->date('birth_date');
            $table->string('phone', 20);
            $table->string('nik', 16)->nullable();
            $table->text('address')->nullable();
            $table->string('occupation')->nullable();
            $table->string('emergency_contact')->nullable();
            $table->string('allergies')->default('Tidak ada');
            $table->timestamps();

            $table->unique(['clinic_id', 'medical_record_number']);
            $table->index('name');
            $table->index('phone');
            $table->index('nik');
        });

        // Kunjungan + antrean (FR-ANT)
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('visit_date');
            $table->unsignedSmallInteger('queue_number');
            $table->time('scheduled_at')->nullable();
            $table->string('complaint', 500)->nullable();
            $table->string('purpose')->nullable();
            $table->string('status', 30)->default('menunggu');
            $table->timestamp('called_at')->nullable();
            $table->timestamp('examined_at')->nullable();
            $table->timestamp('sent_to_cashier_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'doctor_id', 'visit_date', 'queue_number']);
            $table->index(['branch_id', 'visit_date', 'status']);
            $table->index(['patient_id', 'visit_date']);
        });

        // Master tindakan + harga per cabang (FR-SET-03)
        Schema::create('procedures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('share_percent', 5, 2)->nullable(); // tarif bagi hasil per tindakan
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'code']);
        });

        Schema::create('procedure_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('procedure_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('price');
            $table->timestamps();

            $table->unique(['procedure_id', 'branch_id']);
        });

        // Tagihan & pembayaran (FR-KSR)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('discount')->default(0);
            $table->string('discount_reason')->nullable();
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('paid')->default(0);
            $table->string('status', 20)->default('belum'); // belum | sebagian | lunas | batal
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('procedure_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name'); // snapshot nama tindakan
            $table->string('tooth', 10)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_price'); // snapshot harga, master bisa berubah
            $table->unsignedBigInteger('amount');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('method', 20); // tunai | transfer | debit | kredit | qris
            $table->unsignedBigInteger('amount');
            $table->timestamp('paid_at');
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('code', 40)->unique();
            $table->timestamp('printed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'number']);
        });

        // Pengeluaran (FR-PNG)
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_system')->default(false); // gaji & bagi hasil = otomatis
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->string('description')->nullable();
            $table->string('proof_path')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'date']);
        });

        // Jejak audit (NFR-LOG-01, FR-SET-10)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 50);
            $table->nullableMorphs('auditable');
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'audit_logs', 'expenses', 'expense_categories', 'receipts', 'payments',
            'invoice_items', 'invoices', 'procedure_prices', 'procedures', 'visits', 'patients',
            'schedule_overrides', 'doctor_schedules', 'doctors', 'branch_user',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
