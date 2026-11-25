<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantContext;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges Filament tenancy and the BelongsToTenant global scope.
 *
 * Registered as persistent tenant middleware on the /app panel, so it runs after
 * Filament's IdentifyTenant (which has already verified canAccessTenant()) and
 * also on every Livewire update request.
 */
class SetTenantContext
{
    public function __construct(private TenantContext $tenantContext) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Tenant) {
            $this->tenantContext->set($tenant);
        }

        return $next($request);
    }
}
