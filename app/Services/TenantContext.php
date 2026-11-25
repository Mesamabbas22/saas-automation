<?php

namespace App\Services;

use App\Models\Tenant;
use Closure;

/**
 * Holds the tenant for the current request / job.
 *
 * Registered as a singleton (see AppServiceProvider). Set by:
 *  - SetTenantContext middleware in the Filament /app panel (from Filament::getTenant())
 *  - ProcessIncomingMessage job (resolved from metadata.phone_number_id)
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }

    public function clear(): void
    {
        $this->tenant = null;
    }

    /**
     * Run a callback as the given tenant, restoring the previous tenant afterwards.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback();
        } finally {
            $this->tenant = $previous;
        }
    }
}
