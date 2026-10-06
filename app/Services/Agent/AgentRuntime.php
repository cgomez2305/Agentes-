<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Services\Booking\BookingService;
use App\Services\Llm\LlmProvider;
use App\Services\Llm\LlmRequest;
use App\Services\Llm\LlmResult;
use Illuminate\Support\Collection;

/**
 * Decide y redacta la respuesta a los mensajes pendientes de una conversación:
 *   1) reglas deterministas  2) agente LLM con herramientas  3) validación de salida.
 * La respuesta queda guardada como mensaje saliente; el envío lo hace el job.
 */
class AgentRuntime
{
    public function __construct(
        private readonly LlmProvider $llm,
        private readonly KnowledgeSearch $search,
        private readonly PromptBuilder $prompts,
        private readonly QuickReplies $quickReplies,
        private readonly OutputGuard $guard,
        private readonly BookingService $booking,
    ) {}

    public function handle(Conversation $conversation): AgentReply
    {
        $agent = $conversation->agent ?? $conversation->tenant->agent;

        if (! $agent || $conversation->status !== Conversation::STATUS_BOT || $conversation->contact->opted_out) {
            return new AgentReply(null, 'skipped');
        }

        $pending = $this->pendingInbound($conversation);
        if ($pending->isEmpty()) {
            return new AgentReply(null, 'skipped');
        }

        $incoming = $pending->pluck('content')->implode("\n");

        if ($rule = $this->quickReplies->match($agent, $incoming)) {
            return $this->applyRule($conversation, $agent, $rule);
        }

        return $this->runAgent($conversation, $agent, $incoming);
    }

    /**
     * Mensajes del cliente que llegaron después de la última respuesta.
     *
     * @return Collection<int, Message>
     */
    private function pendingInbound(Conversation $conversation): Collection
    {
        $lastOutId = $conversation->messages()->where('direction', Message::OUT)->max('id') ?? 0;

        return $conversation->messages()
            ->where('direction', Message::IN)
            ->where('id', '>', $lastOutId)
            ->orderBy('id')
            ->get();
    }

    private function applyRule(Conversation $conversation, Agent $agent, array $rule): AgentReply
    {
        if ($rule['type'] === QuickReplies::OPT_OUT) {
            $conversation->contact->update(['opted_out' => true]);
            $conversation->update(['status' => Conversation::STATUS_CLOSED]);
        }

        if ($rule['type'] === QuickReplies::HANDOFF) {
            $conversation->handToHuman('El cliente pidió hablar con una persona.');
        }

        $usage = UsageRecord::forTenant($conversation->tenant_id);
        $usage->increment('rule_replies');

        $message = $this->storeReply($conversation, $agent, $rule['reply'], 'rule');

        return new AgentReply($message, $rule['type'] === QuickReplies::HANDOFF ? 'handed_off' : 'replied');
    }

    private function runAgent(Conversation $conversation, Agent $agent, string $incoming): AgentReply
    {
        $tenant = $conversation->tenant;
        $tools = new AgentTools($conversation, $this->search, new BookingTools($conversation, $this->booking, $this->search));

        // RAG acotado: los fragmentos más relevantes al mensaje entran directo
        // al contexto; si el modelo necesita más, usa buscar_conocimiento.
        $knowledge = $this->search->chunks($incoming, config('agentes.knowledge_top_k'))
            ->map(fn ($chunk) => '- '.$chunk->content)
            ->implode("\n");

        $context = $this->prompts->context($tenant, $conversation, $knowledge);
        $tier = $agent->model_tier === 'smart' ? 'smart' : 'fast';

        $result = $this->llm->respond(new LlmRequest(
            model: config("agentes.llm.models.{$tier}"),
            system: $this->prompts->system($tenant, $agent),
            context: $context,
            messages: $this->history($conversation),
            tools: $tools->definitions(),
            maxOutputTokens: $tier === 'smart' ? 4096 : config('agentes.llm.max_output_tokens'),
            maxToolRounds: config('agentes.max_tool_rounds'),
        ), $tools->execute(...));

        UsageRecord::recordLlm($conversation->tenant_id, $result);

        $check = $this->guard->check($result->text, $tools->evidence()."\n".$context);
        $meta = ['tool_calls' => $result->toolCalls, 'stop_reason' => $result->stopReason];

        if (! $check['ok'] || $result->refused()) {
            // Ante una respuesta dudosa es más seguro pasar a un humano que improvisar.
            $conversation->handToHuman($this->describeIssue($check['issue'] ?? null));
            $message = $this->storeReply($conversation, $agent, $agent->handoffMessage(), 'rule', $result, $meta + [
                'blocked_text' => $check['text'],
                'issue' => $check['issue'] ?? 'refusal',
            ]);

            return new AgentReply($message, 'handed_off');
        }

        $message = $this->storeReply($conversation, $agent, $check['text'], 'llm', $result, $meta);

        return new AgentReply($message, match (true) {
            $tools->handedOff() => 'handed_off',
            $message->status === 'draft' => 'drafted',
            default => 'replied',
        });
    }

    /**
     * Historial corto (últimos N mensajes). Lo anterior vive en el resumen.
     *
     * @return list<array{role: string, content: string}>
     */
    private function history(Conversation $conversation): array
    {
        return $conversation->messages()
            ->whereNotNull('content')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
            ->when($conversation->summarized_until_message_id, fn ($q, $id) => $q->where('id', '>', $id))
            ->latest('id')
            ->limit(config('agentes.context_messages'))
            ->get()
            ->reverse()
            ->map(fn (Message $m) => [
                'role' => $m->direction === Message::IN ? 'user' : 'assistant',
                'content' => $m->content,
            ])
            ->values()
            ->all();
    }

    private function storeReply(Conversation $conversation, Agent $agent, string $text, string $source, ?LlmResult $result = null, array $meta = []): Message
    {
        // En modo "sugerir" la respuesta del LLM queda como borrador para que un humano la apruebe.
        $isDraft = $agent->mode === Agent::MODE_SUGGEST && $source === 'llm';

        return $conversation->messages()->create([
            'tenant_id' => $conversation->tenant_id,
            'direction' => Message::OUT,
            'author' => 'bot',
            'type' => 'text',
            'content' => $text,
            'status' => $isDraft ? 'draft' : 'pending',
            'source' => $source,
            'model' => $result?->model,
            'input_tokens' => $result?->inputTokens ?? 0,
            'output_tokens' => $result?->outputTokens ?? 0,
            'cost_usd' => $result?->costUsd() ?? 0,
            'meta' => $meta ?: null,
        ]);
    }

    /**
     * Motivo del traspaso en palabras del negocio, para la bandeja.
     */
    private function describeIssue(?string $issue): string
    {
        return match (true) {
            $issue === null => 'El agente no pudo responder este mensaje.',
            str_starts_with($issue, 'precio_no_verificado:') => 'El agente mencionó un precio que no está en el catálogo ($'.substr($issue, 21).').',
            $issue === 'respuesta_vacia' => 'El agente no generó una respuesta.',
            default => 'La respuesta del agente no pasó la validación.',
        };
    }
}
