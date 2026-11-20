<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi SATUSEHAT tahap 1 (Encounter + Condition). Semua kolom baru nullable → aman untuk data yang ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('satusehat_org_id', 100)->nullable()->after('receipt_footer');
            $table->string('satusehat_client_id')->nullable()->after('satusehat_org_id');
            $table->text('satusehat_client_secret')->nullable()->after('satusehat_client_id'); // terenkripsi (cast encrypted)
            $table->string('satusehat_location_id', 100)->nullable()->after('satusehat_client_secret');
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->string('nik', 16)->nullable()->after('sip_number');
            $table->string('ihs_practitioner_id', 50)->nullable()->after('nik');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('ihs_patient_id', 50)->nullable()->after('nik');
        });

        Schema::create('satusehat_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('antre'); // antre | terkirim | gagal
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->string('encounter_id', 100)->nullable();
            $table->json('condition_ids')->nullable();
            $table->string('environment', 20)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'attempts']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('satusehat_submissions');
        Schema::table('patients', fn (Blueprint $t) => $t->dropColumn('ihs_patient_id'));
        Schema::table('doctors', fn (Blueprint $t) => $t->dropColumn(['nik', 'ihs_practitioner_id']));
        Schema::table('branches', fn (Blueprint $t) => $t->dropColumn([
            'satusehat_org_id', 'satusehat_client_id', 'satusehat_client_secret', 'satusehat_location_id',
        ]));
    }
};
