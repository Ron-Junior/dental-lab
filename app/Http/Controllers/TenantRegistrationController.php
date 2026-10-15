<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Http\Requests\StoreTenantRegistrationRequest;
use App\Jobs\ProvisionTenant;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantRegistrationController extends Controller
{
    public function create(): View
    {
        return view('tenants.register');
    }

    public function store(StoreTenantRegistrationRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $centralConnection = config('tenancy.database.central_connection');

        $tenant = DB::connection($centralConnection)->transaction(function () use ($data): Tenant {
            $tenant = Tenant::query()->create([
                'id' => $data['slug'],
                'name' => $data['laboratory_name'],
                'status' => TenantStatus::Provisioning,
                'owner_name' => $data['admin_name'],
                'owner_email' => $data['email'],
                'owner_password' => $data['password'],
            ]);

            $tenant->domains()->create([
                'domain' => $data['slug'].'.'.config('tenancy.central_domain'),
            ]);

            return $tenant;
        });

        ProvisionTenant::dispatch($tenant->id)->afterCommit();

        return redirect()->route('onboarding.show', $tenant);
    }
}
