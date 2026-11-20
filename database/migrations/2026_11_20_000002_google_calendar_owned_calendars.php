<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Google Calendar opsi A: Service Account membuat kalender sendiri (per dokter + satu kalender Owner)
 * lalu membagikannya otomatis ke email orangnya (ACL + undangan email). Tidak ada data yang dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            // null = kalender Owner ("Semua janji temu").
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('calendar_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->index(['clinic_id', 'doctor_id']);
        });

        Schema::create('google_calendar_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_calendar_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('acl_rule_id')->nullable();
            $table->timestamp('invited_at')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();
            $table->unique(['google_calendar_id', 'email']);
        });

        // Event kini ditulis ke ID kalender milik Service Account, bukan ke email orang.
        Schema::table('appointment_calendar_events', function (Blueprint $table) {
            $table->renameColumn('email', 'calendar_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointment_calendar_events', function (Blueprint $table) {
            $table->renameColumn('calendar_id', 'email');
        });
        Schema::dropIfExists('google_calendar_shares');
        Schema::dropIfExists('google_calendars');
    }
};
