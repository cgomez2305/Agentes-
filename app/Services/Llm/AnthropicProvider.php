<?php

namespace App\Services\Llm;

use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;

class AnthropicProvider implements LlmProvider
{
    /**
     * Modelos que aceptan el fallback del lado del servidor ante un rechazo.
     */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-fable-5-1', 'claude-opus-5'];

    public function __construct(private readonly Client $client) {}

    public function respond(LlmRequest $request, callable $executeTool): LlmResult
    {
        $messages = $this->normalizeHistory($request->messages);
        $tools = array_map(fn (ToolDefinition $tool) => [
            'name' => $tool->name,
            'description' => $tool->description,
            'inputSchema' => $tool->parameters,
        ], $request->tools);

        // El bloque fijo va primero y con cache_control: así el prefijo
        // (herramientas + instrucciones + datos del negocio) se reutiliza entre turnos.
        $system = [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]];
        if ($request->context !== '') {
            $system[] = ['type' => 'text', 'text' => $request->context];
        }

        $result = new LlmResult(text: '', model: $request->model, stopReason: 'end_turn', calls: 0);

        for ($round = 0; $round <= $request->maxToolRounds; $round++) {
            $response = $this->client->beta->messages->create(...$this->params($request, $system, $messages, $tools));

            $result->calls++;
            $result->model = $response->model;
            $result->stopReason = (string) $response->stopReason;
            $result->inputTokens += $response->usage->inputTokens;
            $result->outputTokens += $response->usage->outputTokens;
            $result->cacheReadTokens += (int) $response->usage->cacheReadInputTokens;
            $result->cacheWriteTokens += (int) $response->usage->cacheCreationInputTokens;

            if ($response->stopReason !== 'tool_use' || $round === $request->maxToolRounds) {
                $result->text = $this->extractText($response->content);

                return $result;
            }

            $toolResults = [];
            foreach ($response->content as $block) {
                if (! $block instanceof BetaToolUseBlock) {
                    continue;
                }

                $input = is_array($block->input) ? $block->input : [];
                try {
                    $output = $executeTool($block->name, $input);
                    $isError = false;
                } catch (\Throwable $e) {
                    report($e);
                    $output = 'Error al ejecutar la herramienta.';
                    $isError = true;
                }

                $result->toolCalls[] = ['name' => $block->name, 'input' => $input, 'output' => $output];
                $toolResults[] = [
                    'type' => 'tool_result',
                    'toolUseID' => $block->id,
                    'content' => $output,
                    'isError' => $isError,
                ];
            }

            // Se devuelve el contenido completo (incluye bloques de razonamiento) sin modificar.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function params(LlmRequest $request, array $system, array $messages, array $tools): array
    {
        $params = [
            'model' => $request->model,
            'maxTokens' => $request->maxOutputTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        if ($tools !== []) {
            $params['tools'] = $tools;
        }

        // Haiku 4.5 no admite effort; en los modelos grandes se usa "low",
        // suficiente para conversación y mucho más barato.
        if (! str_starts_with($request->model, 'claude-haiku')) {
            $params['outputConfig'] = ['effort' => config('agentes.llm.effort', 'low')];
        }

        if (in_array($request->model, self::FALLBACK_MODELS, true)) {
            $params['betas'] = ['server-side-fallback-2026-07-01'];
            $params['fallbacks'] = 'default';
        }

        return $params;
    }

    /**
     * La API exige que el primer mensaje sea del usuario; se unen los turnos
     * consecutivos del mismo rol para mantener el historial limpio.
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function normalizeHistory(array $history): array
    {
        $messages = [];

        foreach ($history as $message) {
            if ($messages === [] && $message['role'] !== 'user') {
                continue;
            }

            $last = array_key_last($messages);
            if ($last !== null && $messages[$last]['role'] === $message['role']) {
                $messages[$last]['content'] .= "\n".$message['content'];

                continue;
            }

            $messages[] = ['role' => $message['role'], 'content' => $message['content']];
        }

        return $messages;
    }

    private function extractText(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if ($block->type === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n", $parts));
    }
}
