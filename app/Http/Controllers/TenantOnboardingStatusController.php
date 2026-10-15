<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

class TenantOnboardingStatusController extends Controller
{
    public function __invoke(Tenant $tenant): JsonResponse
    {
        $isActive = $tenant->status === TenantStatus::Active;

        return response()->json([
            'status' => $tenant->status->value,
            'login_url' => $isActive ? route('login', ['tenant' => $tenant->id]) : null,
        ]);
    }
}
