<?php

namespace App\Services;

use App\Enums\Rules;
use App\Models\AuditLog;
use App\Models\CentralUser;
use App\Models\Rule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Database\Models\ImpersonationToken;

class ImpersonateTenantService
{
    /**
     * Generate an impersonation redirect URL for the given tenant and central user.
     *
     * @return array{redirect_url: string, token: string}
     */
    public function impersonate(
        Tenant $tenant,
        CentralUser $centralUser,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        // Find domain for tenant
        $domainRecord = $tenant->domains()->first();
        if ($domainRecord === null) {
            throw new RuntimeException("O laboratório [{$tenant->id}] não possui domínio configurado.");
        }

        $tenantDomain = $domainRecord->domain;

        // In tenant context, find the first User with Rule::Lab
        $targetUserId = $tenant->run(function (): ?int {
            $labRule = Rule::query()->where('name', Rules::Lab->value)->first();

            if ($labRule === null) {
                return null;
            }

            return User::query()
                ->where('rule_id', $labRule->id)
                ->orderBy('id', 'asc')
                ->value('id');
        });

        if ($targetUserId === null) {
            throw new RuntimeException("Nenhum usuário administrador do laboratório foi encontrado para impersonação no tenant [{$tenant->id}].");
        }

        $centralConnection = config('tenancy.database.central_connection');

        return DB::connection($centralConnection)->transaction(function () use (
            $tenant,
            $centralUser,
            $targetUserId,
            $tenantDomain,
            $ipAddress,
            $userAgent
        ): array {
            $tokenString = bin2hex(random_bytes(32));
            $destinationUrl = '/dashboard';

            // Create token in central table
            ImpersonationToken::query()->create([
                'token' => $tokenString,
                'tenant_id' => $tenant->id,
                'user_id' => (string) $targetUserId,
                'auth_guard' => 'web',
                'redirect_url' => $destinationUrl,
                'created_at' => Carbon::now(),
            ]);

            // LGPD Audit Log
            AuditLog::query()->create([
                'central_user_id' => $centralUser->id,
                'tenant_id' => $tenant->id,
                'action' => 'tenant.impersonation.started',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => [
                    'target_user_id' => $targetUserId,
                    'tenant_domain' => $tenantDomain,
                ],
            ]);

            $scheme = request()?->getScheme() ?? 'http';
            $impersonateUrl = "{$scheme}://{$tenantDomain}/impersonate/{$tokenString}";

            return [
                'redirect_url' => $impersonateUrl,
                'token' => $tokenString,
            ];
        });
    }
}
