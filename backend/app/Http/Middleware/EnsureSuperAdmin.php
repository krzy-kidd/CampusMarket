<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user()?->admin;

        abort_unless(
            $request->user()?->account_status === 'active'
                && $admin?->account_status === 'active'
                && $admin?->role === 'super_admin',
            403,
            'An active Super Admin account is required.',
        );

        return $next($request);
    }
}
