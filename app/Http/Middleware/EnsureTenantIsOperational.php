<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsOperational
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (session('impersonated_by_central') === true) {
            return $next($request);
        }

        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            return $next($request);
        }

        if ($tenant->status !== TenantStatus::Active) {
            if ($tenant->status === TenantStatus::PendingPayment) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Pagamento pendente para ativação do laboratório.'], 402)
                    : redirect()->route('onboarding.checkout', ['tenant' => $tenant->getTenantKey()]);
            }

            if ($tenant->status === TenantStatus::Provisioning) {
                return $request->expectsJson()
                    ? response()->json(['message' => 'Laboratório em processo de provisionamento.'], 403)
                    : redirect()->route('onboarding.show', ['tenant' => $tenant->getTenantKey()]);
            }

            return $request->expectsJson()
                ? response()->json(['message' => 'Laboratório indisponível.'], 403)
                : abort(403, 'Laboratório indisponível.');
        }

        if (! $tenant->canAccessOperationalFeatures()) {
            $user = $request->user();
            $message = $tenant->operationalDenialMessage($user);

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 402);
            }

            return redirect()->route('profile')->with('operational_block', $message);
        }

        return $next($request);
    }
}
