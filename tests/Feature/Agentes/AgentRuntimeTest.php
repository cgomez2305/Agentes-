<?php

namespace Tests\Feature\Agentes;

use App\Jobs\RespondToConversation;
use App\Models\Agent;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Agent\AgentRuntime;
use App\Services\Onboarding\TenantProvisioner;
use App\Services\WhatsApp\OutboundSender;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class AgentRuntimeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]])]);
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_quick_replies_answer_without_calling_the_llm(): void
    {
        $conversation = $this->conversationWith('¿Cuál es el horario?');

        $reply = app(AgentRuntime::class)->handle($conversation);

        $this->assertSame('rule', $reply->message->source);
        $this->assertStringContainsString('lunes: 08:00 a 18:00', $this->tenantWithHours()->agent->quick_replies[1]['reply']);
        $this->assertCount(0, $this->llm->requests);
    }

    public function test_asking_for_a_person_hands_off_without_the_llm(): void
    {
        $conversation = $this->conversationWith('Quiero hablar con un asesor por favor');

        $reply = app(AgentRuntime::class)->handle($conversation);

        $this->assertSame('handed_off', $reply->outcome);
        $this->assertSame(Conversation::STATUS_HUMAN, $conversation->fresh()->status);
        $this->assertCount(0, $this->llm->requests);
    }

    public function test_burst_of_messages_produces_a_single_llm_call(): void
    {
        $conversation = $this->conversationWith('Hola');
        $first = $conversation->messages()->first();
        $second = $this->inbound($conversation, 'Quería preguntar por el blanqueamiento');

        // El job del primer mensaje ve que llegó otro después y no hace nada.
        (new RespondToConversation($conversation->id, $first->id))->handle(app(AgentRuntime::class), app(OutboundSender::class), app(TenantContext::class));
        $this->assertCount(0, $this->llm->requests);

        (new RespondToConversation($conversation->id, $second->id))->handle(app(AgentRuntime::class), app(OutboundSender::class), app(TenantContext::class));
        $this->assertCount(1, $this->llm->requests);

        $lastTurn = collect($this->llm->requests[0]->messages)->last();
        $this->assertSame('user', $lastTurn['role']);
        $this->assertStringContainsString('blanqueamiento', $lastTurn['content']);
    }

    public function test_invented_prices_are_blocked_and_handed_to_a_human(): void
    {
        $this->llm->push('La limpieza cuesta $99.000, ¡aprovecha!');
        $conversation = $this->conversationWith('¿Cuánto vale la limpieza?');

        $reply = app(AgentRuntime::class)->handle($conversation);

        $this->assertSame('handed_off', $reply->outcome);
        $this->assertStringNotContainsString('99.000', $reply->message->content);
        $this->assertSame('precio_no_verificado:99.000', $reply->message->meta['issue']);
        $this->assertSame(Conversation::STATUS_HUMAN, $conversation->fresh()->status);
    }

    public function test_catalog_prices_pass_validation(): void
    {
        $this->llm->push('La limpieza dental profunda cuesta $150.000 y dura 45 minutos.');
        $conversation = $this->conversationWith('¿Cuánto vale la limpieza?');

        $reply = app(AgentRuntime::class)->handle($conversation);

        $this->assertSame('replied', $reply->outcome);
        $this->assertSame('pending', $reply->message->status);
    }

    public function test_lead_data_is_saved_and_the_lead_gets_qualified(): void
    {
        $this->llm->push('¡Gracias Laura! Te espero el martes en la mañana.', [
            ['name' => 'guardar_dato_lead', 'input' => ['campo' => 'nombre', 'valor' => 'Laura Gómez']],
            ['name' => 'guardar_dato_lead', 'input' => ['campo' => 'tratamiento', 'valor' => 'limpieza']],
            ['name' => 'guardar_dato_lead', 'input' => ['campo' => 'fecha_preferida', 'valor' => 'martes en la mañana']],
        ]);
        $conversation = $this->conversationWith('Soy Laura Gómez, quiero una limpieza el martes en la mañana');

        app(AgentRuntime::class)->handle($conversation);

        $contact = $conversation->contact->fresh();
        $this->assertSame('calificado', $contact->stage);
        $this->assertSame('limpieza', $contact->lead_data['tratamiento']);
    }

    public function test_knowledge_is_retrieved_into_the_turn_context(): void
    {
        $provisioner = app(TenantProvisioner::class);
        $provisioner->addKnowledge($this->tenant, 'Instalaciones', 'Hay parqueadero gratuito para pacientes en el sótano.');
        $provisioner->addKnowledge($this->tenant, 'Pagos', 'No tenemos convenio con EPS.');
        $conversation = $this->conversationWith('¿Tienen parqueadero?');

        app(AgentRuntime::class)->handle($conversation);

        $request = $this->llm->requests[0];
        $this->assertStringContainsString('parqueadero gratuito', $request->context);
        $this->assertStringNotContainsString('EPS', $request->context);
        // El bloque fijo no cambia entre turnos (caché de prompt).
        $this->assertStringNotContainsString('parqueadero', $request->system);
    }

    public function test_suggest_mode_leaves_a_draft_instead_of_sending(): void
    {
        $this->tenant->agent->update(['mode' => Agent::MODE_SUGGEST]);
        $conversation = $this->conversationWith('Hola, ¿hacen ortodoncia?');

        $reply = app(AgentRuntime::class)->handle($conversation);

        $this->assertSame('drafted', $reply->outcome);
        $this->assertSame('draft', $reply->message->status);
    }

    public function test_tenants_cannot_see_each_others_data(): void
    {
        $this->conversationWith('Hola');
        $other = $this->makeTenant('Inmobiliaria Horizonte', '444555666');

        app(TenantContext::class)->run($other, function () {
            $this->assertSame(0, Contact::count());
            $this->assertSame(0, Message::count());
            $this->assertSame(1, CatalogItem::count());
        });

        $this->assertSame(1, Contact::count());
    }

    private function conversationWith(string $text): Conversation
    {
        $contact = Contact::firstOrCreate(['wa_id' => '573001112233'], ['name' => 'Laura']);
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'channel_id' => $this->tenant->channels()->first()->id,
            'agent_id' => $this->tenant->agent->id,
            'window_expires_at' => now()->addDay(),
        ]);
        $this->inbound($conversation, $text);

        return $conversation->fresh();
    }

    private function inbound(Conversation $conversation, string $text): Message
    {
        return $conversation->messages()->create([
            'direction' => Message::IN,
            'content' => $text,
            'status' => 'received',
        ]);
    }

    private function tenantWithHours(): Tenant
    {
        return Tenant::find($this->tenant->id);
    }
}
