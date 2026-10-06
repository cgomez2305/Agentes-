<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Models\Tenant;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:conectar-whatsapp {negocio : Slug del negocio}
    {phone_number_id : ID del número en la Cloud API}
    {--waba= : ID de la cuenta de WhatsApp Business}
    {--numero= : Número visible, p. ej. +57 300 000 0000}')]
#[Description('Conecta manualmente un número de WhatsApp (mientras se aprueba Embedded Signup)')]
class ConnectChannel extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();

        if (! $tenant) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        $token = $this->secret('Token de acceso permanente (usuario del sistema de Meta)');

        if (! $token) {
            $this->error('El token es obligatorio.');

            return self::FAILURE;
        }

        Channel::withoutGlobalScope('tenant')->updateOrCreate(
            ['phone_number_id' => $this->argument('phone_number_id')],
            [
                'tenant_id' => $tenant->id,
                'type' => 'whatsapp',
                'waba_id' => $this->option('waba'),
                'display_phone' => $this->option('numero'),
                'access_token' => $token,
                'status' => 'active',
            ],
        );

        $this->info("Número conectado a {$tenant->name}. El token queda cifrado en la base de datos.");
        $this->line('Webhook a configurar en Meta: '.rtrim(config('app.url'), '/').'/api/webhooks/whatsapp');

        return self::SUCCESS;
    }
}
