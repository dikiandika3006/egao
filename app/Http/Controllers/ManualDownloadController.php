<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Support\BranchContext;
use App\Support\Manual;

/**
 * Unduh buku manual PDF. Owner: semua peran. Peran lain: hanya manual peran yang sedang aktif.
 */
class ManualDownloadController extends Controller
{
    public function __invoke(BranchContext $context, string $role)
    {
        $role = Role::tryFrom($role) ?? abort(404);

        abort_unless(in_array($role, Manual::visibleFor($context->role()), true), 403, 'Buku manual ini hanya untuk peran terkait.');

        $path = Manual::path($role) ?? abort(404, 'Buku manual belum tersedia.');

        return response()->download($path, Manual::downloadName($role), ['Content-Type' => 'application/pdf']);
    }
}
