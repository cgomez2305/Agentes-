<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Activa el tenant del usuario autenticado para toda la petición.
 */
class SetTenantFromUser
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $request->user()?->tenant;

        abort_unless($tenant, 403, 'Tu usuario no pertenece a ningún negocio.');

        $this->tenants->set($tenant);

        return $next($request);
    }
}
