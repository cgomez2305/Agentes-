<?php

namespace App\Services\Llm;

final readonly class LlmRequest
{
    /**
     * @param  string  $system  Bloque fijo (instrucciones + datos del negocio). Se cachea.
     * @param  string  $context  Bloque variable del turno (conocimiento recuperado, fecha). No se cachea.
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @param  list<ToolDefinition>  $tools
     */
    public function __construct(
        public string $model,
        public string $system,
        public string $context,
        public array $messages,
        public array $tools = [],
        public int $maxOutputTokens = 1024,
        public int $maxToolRounds = 5,
    ) {}
}
