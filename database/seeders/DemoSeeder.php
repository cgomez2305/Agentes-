<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\UsageRecord;
use App\Models\User;
use App\Services\Onboarding\TenantProvisioner;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Negocio de demostración con conversaciones de ejemplo para ver la bandeja.
 * Acceso: demo@agentes.test / demo12345
 */
class DemoSeeder extends Seeder
{
    public function run(TenantProvisioner $provisioner, TenantContext $tenants): void
    {
        if (Tenant::where('slug', 'clinica-dental-sonrisa')->exists()) {
            $this->command?->warn('El negocio de demostración ya existe.');

            return;
        }

        $tenant = $provisioner->create('Clínica Dental Sonrisa', 'clinica', [
            'direccion' => 'Calle 10 # 43-20, Medellín',
            'telefono' => '604 444 0000',
        ]);
        $provisioner->addKnowledge($tenant, 'Preguntas frecuentes', file_get_contents(base_path('ejemplos/clinica-conocimiento.md')));

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Carolina Gómez',
            'email' => 'demo@agentes.test',
            'password' => 'demo12345',
        ]);

        $tenants->run($tenant, function () use ($user) {
            foreach (array_map('str_getcsv', array_slice(file(base_path('ejemplos/clinica-catalogo.csv'), FILE_IGNORE_NEW_LINES), 1)) as $row) {
                CatalogItem::create([
                    'sku' => $row[0], 'name' => $row[1], 'price' => (int) $row[2], 'description' => $row[3],
                    'duration_minutes' => $row[4] === '' ? null : (int) $row[4],
                ]);
            }

            $agent = Agent::first();

            $this->conversation($agent, '573001112233', 'Laura Restrepo', Conversation::STATUS_BOT, 35, [
                ['in', 'Hola! cuánto vale la limpieza dental?'],
                ['out', '¡Hola Laura! La *limpieza dental profunda* cuesta $150.000 COP y dura unos 45 minutos. ¿Quieres que te agende una valoración esta semana?', 'llm', 'read',
                    ['tool_calls' => [['name' => 'consultar_catalogo', 'input' => ['consulta' => 'limpieza dental'], 'output' => '']]], 0.0039],
                ['in', 'Sí, el jueves en la mañana puede ser'],
                ['out', 'Perfecto. ¿Me confirmas tu nombre completo para dejar la solicitud lista?', 'llm', 'read', null, 0.0031],
                ['in', 'Laura Restrepo'],
                ['out', 'Gracias, Laura. Dejé tu solicitud para el jueves en la mañana; el equipo te confirma la hora exacta por aquí.', 'llm', 'delivered', null, 0.0033],
            ], ['nombre' => 'Laura Restrepo', 'tratamiento' => 'limpieza', 'fecha_preferida' => 'jueves en la mañana'], 'calificado');

            $this->conversation($agent, '573104445566', 'Julián Ortiz', Conversation::STATUS_HUMAN, 12, [
                ['in', 'Y si pago de contado la ortodoncia me hacen descuento?'],
                ['out', $agent->handoffMessage(), 'rule', 'read', [
                    'issue' => 'precio_no_verificado:2.900.000',
                    'blocked_text' => 'Sí, de contado te queda en $2.900.000.',
                ], 0.0040],
                ['in', 'Ok, quedo atento'],
            ], ['tratamiento' => 'ortodoncia'], 'nuevo', 'El agente mencionó un precio que no está en el catálogo ($2.900.000).');

            $this->conversation($agent, '573157778899', 'Mariana Zapata', Conversation::STATUS_HUMAN, 6, [
                ['in', '¿Dónde quedan?'],
                ['out', 'Estamos en Calle 10 # 43-20, Medellín. ¿Te gustaría agendar una valoración?', 'rule', 'read'],
                ['in', 'quiero hablar con un asesor'],
                ['out', $agent->handoffMessage(), 'rule', 'read'],
            ], [], 'nuevo', 'El cliente pidió hablar con una persona.');

            $draftConversation = $this->conversation($agent, '573209990011', null, Conversation::STATUS_BOT, 3, [
                ['in', 'Buenas, ¿atienden con Sura?'],
            ]);
            $draftConversation->messages()->create([
                'direction' => Message::OUT, 'author' => 'bot', 'type' => 'text', 'source' => 'llm', 'status' => 'draft',
                'content' => '¡Hola! Sí, tenemos convenio con la prepagada Sura; solo necesitas la orden previa. ¿Quieres que te agendemos una valoración?',
                'model' => 'claude-haiku-4-5', 'cost_usd' => 0.0028,
                'meta' => ['tool_calls' => [['name' => 'buscar_conocimiento', 'input' => ['consulta' => 'convenio Sura'], 'output' => '']]],
            ]);

            $this->conversation($agent, '573012223344', 'Andrés Cardona', Conversation::STATUS_CLOSED, 60 * 30, [
                ['in', 'Hola, ¿hacen blanqueamiento?'],
                ['out', 'Sí, hacemos *blanqueamiento dental en consultorio* en una sola sesión de 60 a 90 minutos; cuesta $650.000 COP. ¿Te gustaría agendarlo?', 'llm', 'read', null, 0.0036],
                ['in', 'Gracias, lo pienso'],
                ['out', 'Con gusto, Andrés. Aquí estamos cuando lo decidas.', 'human', 'read'],
            ], ['tratamiento' => 'blanqueamiento'], 'nuevo', null, $user);
        });

        $tenants->run($tenant, fn () => $this->appointments($tenant));

        // Consumo del mes como lo habría registrado el webhook.
        UsageRecord::forTenant($tenant->id, now($tenant->timezone)->format('Y-m'))->update([
            'conversations' => 5, 'llm_calls' => 9, 'rule_replies' => 3,
            'input_tokens' => 21400, 'output_tokens' => 610, 'cost_usd' => 0.0247,
        ]);

        $this->command?->info('Demo lista: entra con demo@agentes.test / demo12345');
    }

    /**
     * Citas de ejemplo repartidas en la semana actual y la siguiente.
     */
    private function appointments(Tenant $tenant): void
    {
        $monday = now($tenant->timezone)->startOfWeek()->toImmutable();
        $service = fn (string $sku) => CatalogItem::where('sku', $sku)->first();
        $contact = fn (string $waId) => Contact::where('wa_id', $waId)->first();
        $extra = fn (string $waId, string $name) => Contact::firstOrCreate(['wa_id' => $waId], ['name' => $name, 'stage' => 'cliente']);

        $plan = [
            [0, '09:00', 'VAL-01', $extra('573015550101', 'Sofía Arango'), 'human', Appointment::COMPLETED, null],
            [0, '15:30', 'LIM-01', $extra('573015550102', 'Diego Mejía'), 'agent', Appointment::NO_SHOW, null],
            [1, '10:00', 'RES-01', $extra('573015550103', 'Valentina Ríos'), 'agent', Appointment::CONFIRMED, 'Molestia en un molar'],
            [2, '08:30', 'BLA-01', $extra('573015550104', 'Camila Duque'), 'human', Appointment::CONFIRMED, null],
            [3, '09:00', 'LIM-01', $contact('573001112233'), 'agent', Appointment::CONFIRMED, 'Agendó por WhatsApp; primera vez'],
            [3, '11:00', 'VAL-01', $extra('573015550105', 'Tomás Gil'), 'agent', Appointment::CONFIRMED, 'Interesado en ortodoncia'],
            [4, '14:00', 'LIM-01', $extra('573015550106', 'Isabela Mora'), 'agent', Appointment::CANCELLED, null],
            [4, '16:00', 'VAL-01', $extra('573015550107', 'Juan Pablo Vélez'), 'agent', Appointment::CONFIRMED, null],
            [5, '09:30', 'BLA-01', $extra('573015550108', 'Daniela Cano'), 'agent', Appointment::CONFIRMED, null],
            [8, '10:30', 'VAL-01', $extra('573015550109', 'Simón Ochoa'), 'agent', Appointment::CONFIRMED, null],
        ];

        foreach ($plan as [$offset, $time, $sku, $person, $source, $status, $notes]) {
            $item = $service($sku);
            $start = $monday->addDays($offset)->setTimeFromTimeString($time);

            Appointment::create([
                'contact_id' => $person->id,
                'catalog_item_id' => $item->id,
                'title' => $item->name,
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes($item->duration_minutes ?? 30)->utc(),
                'status' => $status,
                'source' => $source,
                'notes' => $notes,
            ]);
        }
    }

    /**
     * @param  list<array>  $script  [dirección, texto, origen, estado, meta, costo]
     */
    private function conversation(Agent $agent, string $waId, ?string $name, string $status, int $minutesAgo, array $script, array $leadData = [], string $stage = 'nuevo', ?string $reason = null, ?User $human = null): Conversation
    {
        $contact = Contact::create(['wa_id' => $waId, 'name' => $name, 'lead_data' => $leadData ?: null, 'stage' => $stage]);
        $start = now()->subMinutes($minutesAgo + count($script));

        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'channel_id' => null,
            'agent_id' => $agent->id,
            'status' => $status,
            'handoff_reason' => $reason,
            'created_at' => $start,
        ]);

        foreach ($script as $i => $line) {
            [$direction, $text, $source, $state, $meta, $cost] = $line + [2 => null, 3 => null, 4 => null, 5 => 0];
            $at = $start->copy()->addMinutes($i);

            $message = new Message([
                'direction' => $direction,
                'author' => $direction === Message::IN ? 'contact' : ($source === 'human' ? 'human' : 'bot'),
                'user_id' => $source === 'human' ? $human?->id : null,
                'type' => 'text',
                'content' => $text,
                'status' => $direction === Message::IN ? 'received' : $state,
                'source' => $source,
                'model' => $source === 'llm' ? 'claude-haiku-4-5' : null,
                'cost_usd' => $cost,
                'meta' => $meta,
            ]);
            $message->conversation_id = $conversation->id;
            $message->created_at = $at;
            $message->updated_at = $at;
            $message->save();

            if ($direction === Message::IN) {
                $conversation->update(['last_inbound_at' => $at, 'window_expires_at' => $at->copy()->addDay()]);
            }
        }

        return $conversation->fresh();
    }
}
