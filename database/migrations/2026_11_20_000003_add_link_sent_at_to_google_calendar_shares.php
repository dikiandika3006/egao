<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Penanda link "tambahkan kalender" sudah dikirim ke penerima (email undangan Google sering tidak terkirim). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_calendar_shares', function (Blueprint $table) {
            $table->timestamp('link_sent_at')->nullable()->after('invited_at');
        });
    }

    public function down(): void
    {
        Schema::table('google_calendar_shares', function (Blueprint $table) {
            $table->dropColumn('link_sent_at');
        });
    }
};
