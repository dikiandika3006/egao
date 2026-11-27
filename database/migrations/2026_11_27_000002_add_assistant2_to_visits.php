<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asisten pendamping boleh 2 per kunjungan (27 Nov 2026); masing-masing mendapat fee asisten penuh.
 * Kolom baru nullable, data lama tidak diubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('visits', function (Blueprint $table) {
            $table->foreignId('assistant2_id')->nullable()->after('assistant_id')->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('visits', fn (Blueprint $t) => $t->dropConstrainedForeignId('assistant2_id'));
    }
};
