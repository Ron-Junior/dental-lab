<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingAccess
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->route('tenant');

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::query()->find($tenant);
        }

        if (! $tenant) {
            abort(404);
        }

        abort_unless(Tenant::isOnboardingAuthorizedForCurrentRequest($request, $tenant), 403);

        return $next($request);
    }
}
