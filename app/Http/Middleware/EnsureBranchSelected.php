<?php

namespace App\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pengguna wajib sudah memilih peran & cabang aktif (FR-AUT-02).
 */
class EnsureBranchSelected
{
    public function __construct(private BranchContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->hasSelection()) {
            return redirect()->route('select-branch');
        }

        return $next($request);
    }
}
