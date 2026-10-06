<?php

namespace App\Services\Llm;

/**
 * Proveedor de pruebas: reproduce una secuencia de turnos guionados y
 * registra las peticiones recibidas.
 */
class FakeProvider implements LlmProvider
{
    /** @var list<LlmRequest> */
    public array $requests = [];

    /**
     * @param  list<array{tools?: list<array{name: string, input: array<string, mixed>}>, text?: string}>  $script
     */
    public function __construct(private array $script = []) {}

    public function push(string $text, array $tools = []): self
    {
        $this->script[] = ['text' => $text, 'tools' => $tools];

        return $this;
    }

    public function respond(LlmRequest $request, callable $executeTool): LlmResult
    {
        $this->requests[] = $request;
        $step = array_shift($this->script) ?? ['text' => 'Respuesta de prueba.'];

        $toolCalls = [];
        foreach ($step['tools'] ?? [] as $call) {
            $toolCalls[] = [
                'name' => $call['name'],
                'input' => $call['input'],
                'output' => $executeTool($call['name'], $call['input']),
            ];
        }

        return new LlmResult(
            text: $step['text'] ?? '',
            model: $request->model,
            stopReason: $step['stop_reason'] ?? 'end_turn',
            inputTokens: 100,
            outputTokens: 20,
            toolCalls: $toolCalls,
        );
    }
}
