<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Onboarding\DocumentExtractor;
use App\Services\Onboarding\TenantProvisioner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('agentes:conocimiento {negocio : Slug del negocio} {archivo : Archivo .txt, .md o .pdf, o una URL https://} {--titulo=}')]
#[Description('Carga un texto (preguntas frecuentes, políticas, servicios) a la base de conocimiento')]
class ImportKnowledge extends Command
{
    public function handle(TenantProvisioner $provisioner, DocumentExtractor $extractor): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();
        $path = $this->argument('archivo');
        $isUrl = (bool) preg_match('#^https?://#i', $path);

        if (! $tenant || (! $isUrl && ! is_file($path))) {
            $this->error(! $tenant ? 'No existe ese negocio.' : 'No se encuentra el archivo.');

            return self::FAILURE;
        }

        try {
            [$type, $title, $content] = match (true) {
                $isUrl => ['url', ...array_values($extractor->fromUrl($path))],
                str_ends_with(strtolower($path), '.pdf') => ['pdf', basename($path), $extractor->fromPdf($path)],
                default => ['text', basename($path), file_get_contents($path)],
            };
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $source = $provisioner->addKnowledge($tenant, $this->option('titulo') ?: $title, $content, $type, $path);

        $this->info("Cargado \"{$source->title}\" en {$source->chunks()->count()} fragmentos.");

        return self::SUCCESS;
    }
}
