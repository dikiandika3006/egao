<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul 04 Rekam medis (FR-RM-01 … 25).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Catatan kunjungan: satu per kunjungan.
        Schema::create('medical_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('complaint', 500)->nullable();          // FR-RM-09
            $table->text('anamnesis')->nullable();                 // FR-RM-13
            $table->string('blood_pressure', 20)->nullable();
            $table->string('current_medication')->nullable();
            $table->text('plan')->nullable();                      // FR-RM-12
            $table->date('follow_up_date')->nullable();
            $table->string('follow_up_label')->nullable();         // mis. "Evaluasi 6 bulan"
            $table->boolean('allergy_confirmed')->default(false);  // FR-RM-21
            $table->string('status', 20)->default('draft');        // draft | final
            $table->unsignedInteger('version')->default(0);        // naik setiap simpan final / koreksi
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });

        // FR-RM-22: rekam medis tidak dihapus, koreksi = versi baru, versi lama tetap tersimpan.
        Schema::create('medical_record_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reason')->nullable();
            $table->json('snapshot');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['medical_record_id', 'version']);
        });

        // Master kondisi gigi & warna odontogram (FR-RM-05, FR-SET-06).
        Schema::create('tooth_condition_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('color', 20); // hex
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['clinic_id', 'code']);
        });

        // Temuan per gigi per kunjungan (FR-RM-03 … 08). Riwayat gigi = query per patient_id + tooth.
        Schema::create('tooth_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('tooth_condition_type_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('tooth');   // nomor FDI 11–48 / 51–85
            $table->json('surfaces')->nullable();   // ["O","M","D","B","L","P"]
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'tooth']);
        });

        // Master ICD-10 (FR-SET-05).
        Schema::create('icd10_codes', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name_en');
            $table->string('name_id')->nullable();
            $table->boolean('is_dental')->default(false);
        });

        Schema::create('doctor_favorite_diagnoses', function (Blueprint $table) {
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->string('icd10_code', 10);
            $table->foreign('icd10_code')->references('code')->on('icd10_codes')->cascadeOnDelete();
            $table->primary(['doctor_id', 'icd10_code']);
        });

        // Diagnosis per kunjungan (FR-RM-10/11).
        Schema::create('diagnoses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->string('icd10_code', 10);
            $table->foreign('icd10_code')->references('code')->on('icd10_codes')->restrictOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->string('tooth_note')->nullable();
            $table->timestamps();

            $table->unique(['visit_id', 'icd10_code']);
        });

        // Resep (FR-RM-18).
        Schema::create('prescriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->string('drug_name');
            $table->string('dose')->nullable();
            $table->string('quantity', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['prescriptions', 'diagnoses', 'doctor_favorite_diagnoses', 'icd10_codes', 'tooth_findings',
            'tooth_condition_types', 'medical_record_versions', 'medical_records'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
