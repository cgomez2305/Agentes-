<?php

namespace App\Services\Agent;

use App\Models\Agent;
use App\Support\Text;

/**
 * Respuestas deterministas que no necesitan LLM (horario, dirección,
 * pedir un humano, darse de baja). Ahorran costo y son instantáneas.
 */
class QuickReplies
{
    public const HANDOFF = 'handoff';

    public const OPT_OUT = 'opt_out';

    public const REPLY = 'reply';

    private const HANDOFF_PATTERNS = [
        'hablar con un humano', 'hablar con una persona', 'hablar con un asesor', 'hablar con alguien',
        'quiero un asesor', 'pasame con un asesor', 'comunicame con un asesor', 'asesor humano',
    ];

    private const OPT_OUT_PATTERNS = ['stop', 'baja', 'no me escriban mas', 'no quiero mas mensajes'];

    /**
     * @return array{type: string, reply: ?string}|null
     */
    public function match(Agent $agent, string $text): ?array
    {
        $normalized = Text::normalize($text);

        if (in_array($normalized, self::OPT_OUT_PATTERNS, true)) {
            return ['type' => self::OPT_OUT, 'reply' => 'Listo, no te enviaremos más mensajes. Si nos escribes de nuevo, con gusto te atendemos.'];
        }

        foreach (self::HANDOFF_PATTERNS as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return ['type' => self::HANDOFF, 'reply' => $agent->handoffMessage()];
            }
        }

        // Las reglas del negocio solo aplican a mensajes cortos y directos;
        // una pregunta larga casi siempre necesita al agente completo.
        if (str_word_count($normalized) > 8) {
            return null;
        }

        foreach ($agent->quick_replies ?? [] as $rule) {
            foreach ($rule['keywords'] ?? [] as $keyword) {
                if (str_contains(' '.$normalized.' ', ' '.Text::normalize($keyword).' ')) {
                    return ['type' => self::REPLY, 'reply' => $rule['reply']];
                }
            }
        }

        return null;
    }
}
