<?php

namespace Tests\Feature\Agentes;

use App\Jobs\ProcessWhatsAppWebhook;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Services\WhatsApp\WebhookParser;
use App\Services\WhatsApp\WhatsAppClient;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class WhatsAppWebhookTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'agentes.whatsapp.verify_token' => 'secreto-verificacion',
            'agentes.whatsapp.app_secret' => 'app-secret',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
    }

    public function test_verifies_the_webhook_with_the_right_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=secreto-verificacion&hub.challenge=12345')
            ->assertOk()
            ->assertSee('12345');

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=otro&hub.challenge=12345')
            ->assertForbidden();
    }

    public function test_rejects_events_with_an_invalid_signature(): void
    {
        Queue::fake();

        $this->postJson('/api/webhooks/whatsapp', $this->inboundPayload('Hola'), ['X-Hub-Signature-256' => 'sha256=falsa'])
            ->assertUnauthorized();

        Queue::assertNothingPushed();
    }

    public function test_answers_an_inbound_message_end_to_end(): void
    {
        $this->fakeLlm()->push('¡Hola Laura! La limpieza dental profunda cuesta $150.000. ¿Te agendo una cita?', [
            ['name' => 'consultar_catalogo', 'input' => ['consulta' => 'limpieza']],
        ]);
        $tenant = $this->makeTenant();

        $this->postSigned($this->inboundPayload('Hola, ¿cuánto vale la limpieza?'))->assertOk();

        $contact = Contact::withoutGlobalScopes()->where('wa_id', '573001112233')->first();
        $this->assertSame($tenant->id, $contact->tenant_id);
        $this->assertSame('Laura', $contact->name);

        $reply = Message::withoutGlobalScopes()->where('direction', Message::OUT)->first();
        $this->assertSame('sent', $reply->status);
        $this->assertSame('wamid.OUT1', $reply->wa_message_id);
        $this->assertSame('llm', $reply->source);
        $this->assertGreaterThan(0, $reply->cost_usd);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/111222333/messages')
            && ($request['type'] ?? null) === 'text'
            && $request['to'] === '573001112233'
            && str_contains($request['text']['body'], '$150.000')
            && $request->hasHeader('Authorization', 'Bearer token-de-prueba'));

        $usage = UsageRecord::withoutGlobalScopes()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(1, $usage->conversations);
        $this->assertSame(1, $usage->llm_calls);
    }

    public function test_ignores_duplicate_deliveries_from_meta(): void
    {
        $this->fakeLlm();
        $this->makeTenant();

        $this->postSigned($this->inboundPayload('Hola'));
        $this->postSigned($this->inboundPayload('Hola'));

        $this->assertSame(1, Message::withoutGlobalScopes()->where('direction', Message::IN)->count());
        $this->assertCount(1, $this->llm->requests);
    }

    public function test_messages_for_unknown_numbers_are_ignored(): void
    {
        $this->fakeLlm();
        $this->makeTenant();

        $this->postSigned($this->inboundPayload('Hola', phoneNumberId: '999'));

        $this->assertSame(0, Message::withoutGlobalScopes()->count());
    }

    public function test_updates_delivery_status(): void
    {
        $this->fakeLlm();
        $this->makeTenant();
        $this->postSigned($this->inboundPayload('Hola'));

        (new ProcessWhatsAppWebhook([
            'object' => 'whatsapp_business_account',
            'entry' => [['changes' => [['field' => 'messages', 'value' => [
                'statuses' => [['id' => 'wamid.OUT1', 'status' => 'read']],
            ]]]]],
        ]))->handle(app(WebhookParser::class), app(WhatsAppClient::class), app(TenantContext::class));

        $this->assertSame('read', Message::withoutGlobalScopes()->where('wa_message_id', 'wamid.OUT1')->value('status'));
    }

    public function test_does_not_answer_when_a_human_has_the_conversation(): void
    {
        $this->fakeLlm();
        $this->makeTenant();
        $this->postSigned($this->inboundPayload('Hola'));

        Conversation::withoutGlobalScopes()->first()->update(['status' => Conversation::STATUS_HUMAN]);
        $this->postSigned($this->inboundPayload('¿Sigue ahí?', wamid: 'wamid.IN2'));

        $this->assertCount(1, $this->llm->requests);
    }

    private function postSigned(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
        ], $body);
    }
}
