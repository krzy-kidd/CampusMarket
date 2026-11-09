<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_if($user->admin()->exists(), 403, 'Administrator accounts cannot use student marketplace features.');
        abort_unless($user->account_status === 'active', 403, 'An active student account is required for marketplace actions.');

        return $next($request);
    }
}
