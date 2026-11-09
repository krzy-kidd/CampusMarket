<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudentAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(
            $request->user()->admin()->exists(),
            403,
            'Administrator accounts cannot use student marketplace features.',
        );

        return $next($request);
    }
}
