<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Tenant activo durante la petición o el job. Los modelos con BelongsToTenant
 * filtran por él automáticamente.
 */
class TenantContext
{
    private ?Tenant $tenant = null;

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
        return $this->tenant?->id;
    }

    /**
     * Ejecuta el callback con el tenant dado y restaura el anterior al terminar.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, callable $callback): mixed
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
