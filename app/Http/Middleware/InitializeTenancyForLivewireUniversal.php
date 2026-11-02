<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;

class InitializeTenancyForLivewireUniversal
{
    public function handle(Request $request, Closure $next)
    {
        // Se for o domínio central, pula a inicialização do tenant
        if (in_array($request->getHost(), config('tenancy.central_domains'))) {
            return $next($request);
        }

        // Se não for central, inicializa o tenant pelo domínio normalmente
        return app(InitializeTenancyByDomain::class)->handle($request, $next);
    }
}
