<?php

namespace App\Console\Commands;

use App\Services\Onboarding\TenantProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:negocio {nombre : Nombre del negocio}
    {--vertical=clinica : Plantilla a usar}
    {--direccion= : Dirección física}
    {--telefono= : Teléfono de contacto}
    {--web= : Sitio web}')]
#[Description('Crea un negocio con su agente a partir de una plantilla por vertical')]
class CreateTenant extends Command
{
    public function handle(TenantProvisioner $provisioner): int
    {
        $vertical = $this->option('vertical');

        if (! in_array($vertical, TenantProvisioner::verticals(), true)) {
            $this->error('Vertical desconocida. Opciones: '.implode(', ', TenantProvisioner::verticals()));

            return self::FAILURE;
        }

        $profile = array_filter([
            'direccion' => $this->option('direccion'),
            'telefono' => $this->option('telefono'),
            'web' => $this->option('web'),
        ]);

        $tenant = $provisioner->create($this->argument('nombre'), $vertical, $profile);

        $this->info("Negocio creado: {$tenant->name} (slug: {$tenant->slug}, vertical: {$vertical}).");
        $this->line('Siguientes pasos:');
        $this->line("  php artisan agentes:conocimiento {$tenant->slug} ruta/al/archivo.md");
        $this->line("  php artisan agentes:catalogo {$tenant->slug} ruta/al/catalogo.csv");
        $this->line("  php artisan agentes:chat {$tenant->slug}");

        return self::SUCCESS;
    }
}
