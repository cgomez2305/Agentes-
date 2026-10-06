<?php

namespace Tests\Feature\Agentes;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentRuntime;
use App\Services\Agent\ConversationSummarizer;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class ConversationSummarizerTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    public function test_old_messages_are_summarized_and_leave_the_context(): void
    {
        config(['agentes.context_messages' => 4, 'agentes.summary_batch' => 4]);
        $this->fakeLlm()->push('Laura busca una limpieza para el jueves en la mañana.');
        $tenant = $this->makeTenant();
        app(TenantContext::class)->set($tenant);

        $conversation = Conversation::create([
            'contact_id' => Contact::create(['wa_id' => '573001112233', 'name' => 'Laura'])->id,
            'agent_id' => $tenant->agent->id,
            'window_expires_at' => now()->addDay(),
        ]);
        foreach (range(1, 9) as $i) {
            $conversation->messages()->create([
                'direction' => $i % 2 ? Message::IN : Message::OUT,
                'content' => "mensaje {$i}",
                'status' => 'sent',
            ]);
        }

        $this->assertTrue(app(ConversationSummarizer::class)->summarizeIfNeeded($conversation));

        $conversation->refresh();
        $this->assertSame('Laura busca una limpieza para el jueves en la mañana.', $conversation->summary);
        $this->assertStringContainsString("Cliente: mensaje 1\nAsistente: mensaje 2", $this->llm->requests[0]->messages[0]['content']);

        // Con pocos mensajes nuevos no se vuelve a resumir.
        $this->assertFalse(app(ConversationSummarizer::class)->summarizeIfNeeded($conversation));

        // El agente recibe el resumen y solo los mensajes posteriores.
        app(AgentRuntime::class)->handle($conversation);
        $request = $this->llm->requests[1];
        $this->assertStringContainsString('Resumen de la conversación previa: Laura busca una limpieza', $request->context);
        $this->assertSame('mensaje 6', $request->messages[0]['content']);
        $this->assertCount(4, $request->messages);
    }
}
