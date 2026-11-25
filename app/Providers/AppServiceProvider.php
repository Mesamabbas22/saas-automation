<?php

namespace App\Providers;

use App\Services\TenantContext;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /**
         * One TenantContext per request / job. `scoped` (not `singleton`) so it is
         * reset between queued jobs on long-running workers and Octane requests,
         * preventing one tenant's context from bleeding into the next job.
         */
        $this->app->scoped(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
