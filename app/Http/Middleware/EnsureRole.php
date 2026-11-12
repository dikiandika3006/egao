<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pakai: ->middleware('role:owner,admin'). Mengecek peran AKTIF, bukan semua peran akun.
 */
class EnsureRole
{
    public function __construct(private BranchContext $context) {}

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = array_map(fn (string $r) => Role::from($r), $roles);

        abort_unless($this->context->is(...$allowed), 403, 'Menu ini tidak tersedia untuk peran Anda.');

        return $next($request);
    }
}
