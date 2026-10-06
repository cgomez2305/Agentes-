<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Onboarding\TenantProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:conocimiento {negocio : Slug del negocio} {archivo : Archivo .txt o .md} {--titulo=}')]
#[Description('Carga un texto (preguntas frecuentes, políticas, servicios) a la base de conocimiento')]
class ImportKnowledge extends Command
{
    public function handle(TenantProvisioner $provisioner): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();
        $path = $this->argument('archivo');

        if (! $tenant || ! is_file($path)) {
            $this->error(! $tenant ? 'No existe ese negocio.' : 'No se encuentra el archivo.');

            return self::FAILURE;
        }

        $source = $provisioner->addKnowledge(
            $tenant,
            $this->option('titulo') ?: basename($path),
            file_get_contents($path),
            origin: $path,
        );

        $this->info("Cargado \"{$source->title}\" en {$source->chunks()->count()} fragmentos.");

        return self::SUCCESS;
    }
}
