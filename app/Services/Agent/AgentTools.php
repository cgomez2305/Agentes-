<?php

namespace App\Services\Agent;

use App\Models\Conversation;
use App\Services\Llm\ToolDefinition;

/**
 * Herramientas que el agente puede usar durante un turno. Los precios solo
 * salen de aquí (catálogo), nunca del conocimiento general del modelo.
 */
class AgentTools
{
    /** Datos citados por las herramientas en este turno (para validar la salida). */
    private string $evidence = '';

    private bool $handedOff = false;

    public function __construct(
        private readonly Conversation $conversation,
        private readonly KnowledgeSearch $search,
    ) {}

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        $fields = collect($this->conversation->agent?->qualification_fields ?? [])->pluck('key')->all();

        return [
            new ToolDefinition(
                name: 'buscar_conocimiento',
                description: 'Busca en la información del negocio (políticas, preguntas frecuentes, '
                    .'detalles de servicios). Úsala antes de responder algo que no esté en tus instrucciones.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'consulta' => ['type' => 'string', 'description' => 'Qué buscar, en palabras clave.'],
                    ],
                    'required' => ['consulta'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolDefinition(
                name: 'consultar_catalogo',
                description: 'Consulta productos o servicios del catálogo con su precio, duración y '
                    .'disponibilidad. Es la ÚNICA fuente válida de precios.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'consulta' => ['type' => 'string', 'description' => 'Nombre o tipo de producto/servicio.'],
                    ],
                    'required' => ['consulta'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolDefinition(
                name: 'guardar_dato_lead',
                description: 'Guarda un dato que el cliente compartió (nombre, presupuesto, fecha deseada, '
                    .'ciudad, etc.) para calificar el lead.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'campo' => array_filter([
                            'type' => 'string',
                            'description' => 'Nombre corto del dato, en minúsculas y con guion bajo.',
                            'enum' => $fields === [] ? null : array_values(array_unique([...$fields, 'nombre', 'otro'])),
                        ]),
                        'valor' => ['type' => 'string'],
                    ],
                    'required' => ['campo', 'valor'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolDefinition(
                name: 'pasar_a_humano',
                description: 'Transfiere la conversación a una persona del equipo. Úsala si el cliente lo pide, '
                    .'si hay una queja, un caso que no puedes resolver con la información disponible o '
                    .'cuando el cliente está listo para cerrar y se requiere confirmación humana.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'motivo' => ['type' => 'string', 'description' => 'Resumen breve para el asesor.'],
                    ],
                    'required' => ['motivo'],
                    'additionalProperties' => false,
                ],
            ),
        ];
    }

    public function execute(string $name, array $input): string
    {
        $output = match ($name) {
            'buscar_conocimiento' => $this->searchKnowledge((string) ($input['consulta'] ?? '')),
            'consultar_catalogo' => $this->searchCatalog((string) ($input['consulta'] ?? '')),
            'guardar_dato_lead' => $this->saveLeadData((string) ($input['campo'] ?? ''), (string) ($input['valor'] ?? '')),
            'pasar_a_humano' => $this->handOff((string) ($input['motivo'] ?? 'Solicitado por el agente')),
            default => 'Herramienta desconocida.',
        };

        $this->evidence .= "\n".$output;

        return $output;
    }

    public function evidence(): string
    {
        return $this->evidence;
    }

    public function handedOff(): bool
    {
        return $this->handedOff;
    }

    private function searchKnowledge(string $query): string
    {
        $chunks = $this->search->chunks($query, config('agentes.knowledge_top_k'));

        if ($chunks->isEmpty()) {
            return 'Sin resultados. No inventes la respuesta: dilo con naturalidad u ofrece pasar con un asesor.';
        }

        return $chunks->map(fn ($chunk) => '- '.$chunk->content)->implode("\n");
    }

    private function searchCatalog(string $query): string
    {
        $items = $this->search->catalog($query, 5);

        if ($items->isEmpty()) {
            return 'No hay productos o servicios que coincidan. No des precios.';
        }

        return $items->map(function ($item) {
            $parts = [$item->name];
            $parts[] = $item->formattedPrice() ?? 'precio a cotizar';
            if ($item->duration_minutes) {
                $parts[] = $item->duration_minutes.' min';
            }
            if ($item->description) {
                $parts[] = $item->description;
            }

            return '- '.implode(' | ', $parts);
        })->implode("\n");
    }

    private function saveLeadData(string $field, string $value): string
    {
        $field = trim(strtolower($field));

        if ($field === '' || $value === '') {
            return 'Dato vacío, no se guardó.';
        }

        $contact = $this->conversation->contact;

        if ($field === 'nombre' && ! $contact->name) {
            $contact->name = $value;
        }

        $data = $contact->lead_data ?? [];
        $data[$field] = $value;
        $contact->lead_data = $data;

        $required = collect($this->conversation->agent?->qualification_fields ?? [])->pluck('key');
        if ($required->isNotEmpty() && $required->every(fn ($key) => filled($data[$key] ?? null)) && $contact->stage === 'nuevo') {
            $contact->stage = 'calificado';
        }

        $contact->save();

        return "Guardado: {$field}.";
    }

    private function handOff(string $reason): string
    {
        $this->conversation->handToHuman($reason);
        $this->handedOff = true;

        return 'Conversación transferida. Avisa al cliente que una persona le responderá pronto.';
    }
}
