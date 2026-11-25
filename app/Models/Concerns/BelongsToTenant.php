<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the tenant held by TenantContext.
 *
 * - Every query gets `where tenant_id = ?` when a tenant is set.
 * - New records get tenant_id auto-filled when a tenant is set.
 * - When no tenant is set (super admin panel, console, seeders) no scope is applied.
 *   Use Model::withoutTenantScope() to bypass it explicitly.
 */
trait BelongsToTenant
{
    public const TENANT_SCOPE = 'tenant';

    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(self::TENANT_SCOPE, function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId !== null) {
                $builder->where($builder->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId === null) {
                return;
            }

            if (empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            } elseif ((int) $model->tenant_id !== $tenantId) {
                // Never allow writing into another tenant while a tenant is active.
                throw new \LogicException('Cannot create a record for a different tenant than the active one.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function withoutTenantScope(): Builder
    {
        return static::query()->withoutGlobalScope(self::TENANT_SCOPE);
    }
}
