<?php

namespace App\Http\Middleware;

use App\Models\CentralUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCentralAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('central');

        $user = Auth::guard('central')->user();

        if (! $user instanceof CentralUser) {
            abort(403, 'Acesso restrito aos administradores centrais.');
        }

        return $next($request);
    }
}
