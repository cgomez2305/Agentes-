<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\FollowUp\FollowUpService;
use App\Support\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('agentes:seguimientos')]
#[Description('Envía los seguimientos a clientes sin respuesta y los recordatorios de cita')]
class RunFollowUps extends Command
{
    public function handle(FollowUpService $followUps, TenantContext $tenants): int
    {
        Tenant::query()->each(function (Tenant $tenant) use ($followUps, $tenants) {
            try {
                $result = $tenants->run($tenant, fn () => $followUps->run($tenant));
            } catch (\Throwable $e) {
                report($e);
                $this->error("{$tenant->slug}: {$e->getMessage()}");

                return;
            }

            if ($result['nudges'] || $result['reminders']) {
                $this->line("{$tenant->slug}: {$result['nudges']} seguimientos, {$result['reminders']} recordatorios.");
            }
        });

        return self::SUCCESS;
    }
}
