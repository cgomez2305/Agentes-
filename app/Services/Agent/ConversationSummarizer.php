<?php

namespace App\Services\Agent;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\UsageRecord;
use App\Services\Llm\LlmProvider;
use App\Services\Llm\LlmRequest;

/**
 * Mantiene un resumen acumulado de la conversación. El agente recibe solo
 * los últimos mensajes completos más este resumen, lo que acota el costo
 * por turno aunque la conversación sea larga.
 */
class ConversationSummarizer
{
    public function __construct(private readonly LlmProvider $llm) {}

    /**
     * @return bool true si se actualizó el resumen.
     */
    public function summarizeIfNeeded(Conversation $conversation): bool
    {
        $keep = config('agentes.context_messages');

        $messages = $conversation->messages()
            ->whereNotNull('content')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
            ->when($conversation->summarized_until_message_id, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderBy('id')
            ->get();

        $older = $messages->slice(0, max(0, $messages->count() - $keep));

        if ($older->count() < config('agentes.summary_batch')) {
            return false;
        }

        $transcript = $older->map(fn (Message $m) => match ($m->direction) {
            Message::IN => 'Cliente: ',
            default => $m->author === 'human' ? 'Asesor: ' : 'Asistente: ',
        }.$m->content)->implode("\n");

        $previous = $conversation->summary ?: '(sin resumen previo)';

        $result = $this->llm->respond(new LlmRequest(
            model: config('agentes.llm.models.fast'),
            system: <<<'PROMPT'
            Resumes conversaciones de WhatsApp entre un negocio y su cliente para que el asistente
            pueda continuarlas sin leer todo el historial. Escribe en español, en tercera persona,
            máximo 90 palabras. Conserva: qué necesita el cliente, datos que dio (nombre, fechas,
            presupuesto, producto), lo que se le ofreció o prometió, y lo que quedó pendiente.
            Responde solo con el resumen, sin título ni viñetas.
            PROMPT,
            context: '',
            messages: [[
                'role' => 'user',
                'content' => "Resumen anterior:\n{$previous}\n\nMensajes nuevos:\n{$transcript}",
            ]],
            maxOutputTokens: 400,
            maxToolRounds: 0,
        ), fn () => '');

        UsageRecord::recordLlm($conversation->tenant_id, $result);

        if ($result->text === '' || $result->refused()) {
            return false;
        }

        $conversation->update([
            'summary' => $result->text,
            'summarized_until_message_id' => $older->last()->id,
        ]);

        return true;
    }
}
