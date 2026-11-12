<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable()->after('name');
        });

        // Backfill: username = bagian sebelum "@" pada email lama.
        foreach (DB::table('users')->orderBy('id')->get(['id', 'email']) as $user) {
            $base = strtolower(strstr((string) $user->email, '@', true) ?: 'user'.$user->id);
            $username = $base;
            $i = 2;
            while (DB::table('users')->where('username', $username)->exists()) {
                $username = $base.$i++;
            }
            DB::table('users')->where('id', $user->id)->update(['username' => $username]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 50)->nullable(false)->unique()->change();
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
