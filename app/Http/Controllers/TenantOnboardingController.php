<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Enums\TenantStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class TenantOnboardingController extends Controller
{
    public function show(Tenant $tenant): View|RedirectResponse
    {
        if ($tenant->status === TenantStatus::Active) {
            return redirect()->route('login', ['tenant' => $tenant->id]);
        }

        return view('tenants.welcome', [
            'tenant' => $tenant,
            'statusUrl' => route('onboarding.status', $tenant),
            'loginUrl' => route('login', ['tenant' => $tenant->id]),
        ]);
    }
}
