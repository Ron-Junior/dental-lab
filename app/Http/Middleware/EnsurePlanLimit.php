<?php

namespace App\Http\Middleware;

use App\Enums\PlanResource;
use App\Services\PlanLimits\PlanLimitExceededException;
use App\Services\PlanLimits\TenantLimitChecker;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanLimit
{
    public function __construct(protected TenantLimitChecker $checker) {}

    /**
     * Uso: `plan.limit:users`, `plan.limit:storage` ou `plan.limit:monthly_items`.
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        try {
            $this->checker->assertCanCreate(PlanResource::fromAlias($resource));
        } catch (PlanLimitExceededException $exception) {
            $message = $exception->messageFor($request->user());

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $message,
                    'usage' => $exception->usage->toArray(),
                ], 403);
            }

            abort(403, $message);
        }

        return $next($request);
    }
}
