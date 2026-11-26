<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Peran "asisten" digabung ke "admin" (Admin / Asisten). Tidak ada data yang dihapus:
 * - users.roles: "asisten" → "admin" (tanpa duplikat).
 * - settings.role_permissions: izin peran "asisten" dibuang (peran tidak ada lagi).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('roles', 'like', '%asisten%')->orderBy('id')->each(function ($user) {
            $roles = json_decode((string) $user->roles, true) ?: [];
            $merged = array_values(array_unique(array_map(fn ($r) => $r === 'asisten' ? 'admin' : $r, $roles)));
            if ($merged !== $roles) {
                DB::table('users')->where('id', $user->id)->update(['roles' => json_encode($merged)]);
            }
        });

        $stored = DB::table('settings')->where('key', 'role_permissions')->value('value');
        $matrix = $stored ? json_decode($stored, true) : null;
        if (is_array($matrix) && array_key_exists('asisten', $matrix)) {
            unset($matrix['asisten']);
            DB::table('settings')->where('key', 'role_permissions')->update(['value' => json_encode($matrix)]);
        }
    }

    public function down(): void
    {
        // Tidak dikembalikan: setelah digabung tidak bisa dibedakan lagi mana yang dulu "asisten".
    }
};
