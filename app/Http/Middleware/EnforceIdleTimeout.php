<?php

namespace App\Http\Middleware;

use App\Support\Settings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keluar otomatis bila tidak ada aktivitas melewati batas jam (Pengaturan → Aplikasi → Keamanan).
 * Dipasang di grup web setelah sesi aktif, sehingga request Livewire juga dihitung sebagai aktivitas.
 */
class EnforceIdleTimeout
{
    public const KEY = 'last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $last = (int) $request->session()->get(self::KEY, 0);
            $limit = rescue(fn () => Settings::idleHours(), 8, false) * 3600;

            if ($last && time() - $last > $limit) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $message = 'Sesi berakhir karena tidak ada aktivitas. Silakan masuk kembali.';

                return $request->expectsJson() || $request->hasHeader('X-Livewire')
                    ? response()->json(['message' => $message], 419)
                    : redirect()->route('login')->with('status', $message);
            }

            $request->session()->put(self::KEY, time());
        }

        return $next($request);
    }
}
