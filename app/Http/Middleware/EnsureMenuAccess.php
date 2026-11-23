<?php

namespace App\Http\Middleware;

use App\Support\Access;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Halaman modul hanya untuk peran yang punya izin Lihat (Pengaturan › Hak akses peran). */
class EnsureMenuAccess
{
    public function handle(Request $request, Closure $next, string $menu): Response
    {
        abort_unless(Access::canOpen($menu), 403, 'Menu ini tidak tersedia untuk peran Anda.');

        return $next($request);
    }
}
