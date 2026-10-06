<?php

namespace Tests\Feature\Agentes\Concerns;

use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\Tenant;
use App\Services\Llm\FakeProvider;
use App\Services\Llm\LlmProvider;
use App\Services\Onboarding\TenantProvisioner;
use App\Support\TenantContext;

trait BuildsTenants
{
    protected FakeProvider $llm;

    protected function fakeLlm(): FakeProvider
    {
        $this->llm = new FakeProvider;
        $this->app->instance(LlmProvider::class, $this->llm);

        return $this->llm;
    }

    protected function makeTenant(string $name = 'Clínica Dental Sonrisa', string $phoneNumberId = '111222333'): Tenant
    {
        $tenant = app(TenantProvisioner::class)->create($name, 'clinica', [
            'direccion' => 'Calle 10 # 43-20, Medellín',
        ]);

        // Abierto siempre, para que las pruebas no dependan de la hora.
        $tenant->update(['business_hours' => null]);

        app(TenantContext::class)->run($tenant, function () use ($phoneNumberId) {
            Channel::create([
                'phone_number_id' => $phoneNumberId,
                'access_token' => 'token-de-prueba',
                'display_phone' => '+57 300 000 0000',
            ]);

            CatalogItem::create(['sku' => 'LIM-01', 'name' => 'Limpieza dental profunda', 'price' => 150000, 'duration_minutes' => 45]);
        });

        return $tenant->fresh();
    }

    protected function inboundPayload(string $text, string $wamid = 'wamid.IN1', string $from = '573001112233', string $phoneNumberId = '111222333'): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WABA',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['display_phone_number' => '573000000000', 'phone_number_id' => $phoneNumberId],
                        'contacts' => [['profile' => ['name' => 'Laura'], 'wa_id' => $from]],
                        'messages' => [[
                            'from' => $from,
                            'id' => $wamid,
                            'timestamp' => (string) time(),
                            'type' => 'text',
                            'text' => ['body' => $text],
                        ]],
                    ],
                ]],
            ]],
        ];
    }
}
