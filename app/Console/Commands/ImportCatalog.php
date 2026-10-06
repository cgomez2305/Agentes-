<?php

namespace App\Console\Commands;

use App\Models\CatalogItem;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:catalogo {negocio : Slug del negocio} {archivo : CSV con columnas nombre,precio[,descripcion,duracion_min,sku,tipo]}')]
#[Description('Importa productos o servicios desde un CSV (los precios del agente salen solo de aquí)')]
class ImportCatalog extends Command
{
    public function handle(TenantContext $tenants): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();
        $path = $this->argument('archivo');

        if (! $tenant || ! is_file($path)) {
            $this->error(! $tenant ? 'No existe ese negocio.' : 'No se encuentra el archivo.');

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($h) => strtolower(trim($h)), fgetcsv($handle, escape: ''));
        $count = 0;

        $tenants->run($tenant, function () use ($handle, $header, &$count) {
            while (($row = fgetcsv($handle, escape: '')) !== false) {
                if (count($row) !== count($header)) {
                    continue;
                }

                $data = array_combine($header, array_map('trim', $row));
                $price = preg_replace('/\D/', '', $data['precio'] ?? '');
                $attributes = [
                    'name' => $data['nombre'],
                    'kind' => ($data['tipo'] ?? '') === 'producto' ? 'product' : 'service',
                    'description' => $data['descripcion'] ?? null,
                    'price' => $price === '' ? null : (int) $price,
                    'duration_minutes' => ($data['duracion_min'] ?? '') === '' ? null : (int) $data['duracion_min'],
                    'is_available' => true,
                ];

                CatalogItem::updateOrCreate(
                    filled($data['sku'] ?? null) ? ['sku' => $data['sku']] : ['name' => $data['nombre']],
                    $attributes,
                );
                $count++;
            }
        });

        fclose($handle);
        $this->info("Importados {$count} ítems al catálogo de {$tenant->name}.");

        return self::SUCCESS;
    }
}
