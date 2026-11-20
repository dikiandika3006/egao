<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sinkron janji temu ke Google Calendar (service account menulis ke kalender dokter & Owner).
 * Janji yang sudah ada tidak dikirim (calendar_dirty default false).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('calendar_email')->nullable()->after('name');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('calendar_dirty')->default(false)->after('created_by');
            $table->unsignedSmallInteger('calendar_attempts')->default(0)->after('calendar_dirty');
            $table->string('calendar_error', 500)->nullable()->after('calendar_attempts');
            $table->timestamp('calendar_synced_at')->nullable()->after('calendar_error');
            $table->index('calendar_dirty');
        });

        Schema::create('appointment_calendar_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('google_event_id');
            $table->timestamps();
            $table->unique(['appointment_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_calendar_events');

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['calendar_dirty']);
            $table->dropColumn(['calendar_dirty', 'calendar_attempts', 'calendar_error', 'calendar_synced_at']);
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('calendar_email');
        });
    }
};
