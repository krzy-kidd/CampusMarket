<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveVerificationAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user()?->admin;

        abort_unless(
            $admin?->isActiveVerificationAdmin() && $request->user()?->account_status === 'active',
            403,
            'An active verification administrator account is required.',
        );

        return $next($request);
    }
}
