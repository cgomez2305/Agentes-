<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Onboarding\CatalogImporter;
use App\Support\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('agentes:catalogo {negocio : Slug del negocio} {archivo : CSV con columnas nombre,precio[,descripcion,duracion_min,sku,tipo]}')]
#[Description('Importa productos o servicios desde un CSV (los precios del agente salen solo de aquí)')]
class ImportCatalog extends Command
{
    public function handle(TenantContext $tenants, CatalogImporter $importer): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();
        $path = $this->argument('archivo');

        if (! $tenant || ! is_file($path)) {
            $this->error(! $tenant ? 'No existe ese negocio.' : 'No se encuentra el archivo.');

            return self::FAILURE;
        }

        try {
            $count = $tenants->run($tenant, fn () => $importer->import($path));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Importados {$count} ítems al catálogo de {$tenant->name}.");

        return self::SUCCESS;
    }
}
