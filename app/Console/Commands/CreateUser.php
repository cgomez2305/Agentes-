<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:usuario {negocio : Slug del negocio} {email} {--nombre= : Nombre visible en la bandeja} {--rol=owner : owner o agente}')]
#[Description('Crea una persona del equipo que puede entrar a la bandeja del negocio')]
class CreateUser extends Command
{
    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('negocio'))->first();

        if (! $tenant) {
            $this->error('No existe ese negocio.');

            return self::FAILURE;
        }

        $password = $this->secret('Contraseña (mínimo 8 caracteres)');

        if (! $password || strlen($password) < 8) {
            $this->error('La contraseña debe tener al menos 8 caracteres.');

            return self::FAILURE;
        }

        $user = User::updateOrCreate(['email' => $this->argument('email')], [
            'tenant_id' => $tenant->id,
            'name' => $this->option('nombre') ?: strstr($this->argument('email'), '@', true),
            'role' => $this->option('rol'),
            'password' => $password,
        ]);

        $this->info("Listo: {$user->email} puede entrar a la bandeja de {$tenant->name} en ".url('/ingresar'));

        return self::SUCCESS;
    }
}
