<?php

namespace App\Livewire\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Livewire atiende cada acción en una petición propia: este boot fija el
 * negocio del usuario para que todas las consultas queden aisladas.
 */
trait ScopedToTenant
{
    public function bootScopedToTenant(TenantContext $tenants): void
    {
        $tenant = Auth::user()?->tenant;
        abort_unless($tenant, 403);
        $tenants->set($tenant);
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
