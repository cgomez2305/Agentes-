<?php

namespace Tests\Unit;

use Anthropic\Client;
use App\Services\Llm\AnthropicProvider;
use App\Services\Llm\LlmRequest;
use App\Services\Llm\ToolDefinition;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

class AnthropicProviderTest extends TestCase
{
    private array $history = [];

    public function test_runs_the_tool_loop_and_caches_the_fixed_prompt(): void
    {
        $provider = $this->provider([
            $this->apiMessage('tool_use', [
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'consultar_catalogo', 'input' => ['consulta' => 'limpieza']],
            ], input: 1200, output: 40),
            $this->apiMessage('end_turn', [
                ['type' => 'text', 'text' => 'La limpieza cuesta $150.000 COP.'],
            ], input: 1300, output: 25, cacheRead: 1000),
        ]);

        $executed = [];
        $result = $provider->respond(new LlmRequest(
            model: 'claude-haiku-4-5',
            system: 'Instrucciones fijas',
            context: 'Contexto del turno',
            messages: [
                ['role' => 'assistant', 'content' => 'Mensaje viejo del bot sin turno previo del usuario'],
                ['role' => 'user', 'content' => 'Hola'],
                ['role' => 'user', 'content' => '¿Cuánto vale la limpieza?'],
            ],
            tools: [new ToolDefinition('consultar_catalogo', 'Catálogo', [
                'type' => 'object',
                'properties' => ['consulta' => ['type' => 'string']],
                'required' => ['consulta'],
            ])],
        ), function (string $name, array $input) use (&$executed) {
            $executed[] = [$name, $input];

            return '- Limpieza dental profunda | $150.000 COP';
        });

        $this->assertSame('La limpieza cuesta $150.000 COP.', $result->text);
        $this->assertSame([['consultar_catalogo', ['consulta' => 'limpieza']]], $executed);
        $this->assertSame(2, $result->calls);
        $this->assertSame(2500, $result->inputTokens);
        $this->assertSame(1000, $result->cacheReadTokens);
        $this->assertGreaterThan(0, $result->costUsd());

        $first = $this->body(0);
        $this->assertSame('claude-haiku-4-5', $first['model']);
        $this->assertSame(['type' => 'ephemeral'], $first['system'][0]['cache_control']);
        $this->assertArrayNotHasKey('cache_control', $first['system'][1]);
        $this->assertArrayNotHasKey('output_config', $first, 'Haiku 4.5 no admite effort');
        $this->assertArrayNotHasKey('fallbacks', $first);
        // El historial empieza en el usuario y une los turnos consecutivos.
        $this->assertCount(1, $first['messages']);
        $this->assertSame("Hola\n¿Cuánto vale la limpieza?", $first['messages'][0]['content']);
        $this->assertSame('consultar_catalogo', $first['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $first['tools'][0]);

        $second = $this->body(1);
        $this->assertSame('assistant', $second['messages'][1]['role']);
        $toolResult = $second['messages'][2]['content'][0];
        $this->assertSame('tool_result', $toolResult['type']);
        $this->assertSame('toolu_1', $toolResult['tool_use_id']);
    }

    public function test_smart_tier_uses_effort_and_server_side_fallback(): void
    {
        $provider = $this->provider([
            $this->apiMessage('end_turn', [['type' => 'text', 'text' => 'Listo.']], model: 'claude-opus-5-5'),
        ]);

        $provider->respond(new LlmRequest(
            model: 'claude-opus-5-5',
            system: 'Fijo',
            context: '',
            messages: [['role' => 'user', 'content' => 'Hola']],
        ), fn () => '');

        $body = $this->body(0);
        $this->assertSame(['effort' => 'low'], $body['output_config']);
        $this->assertSame('default', $body['fallbacks']);
        $this->assertCount(1, $body['system']);
        $this->assertStringContainsString(
            'server-side-fallback-2026-07-01',
            $this->history[0]['request']->getHeaderLine('anthropic-beta'),
        );
    }

    private function provider(array $responses): AnthropicProvider
    {
        $stack = HandlerStack::create(new MockHandler(array_map(
            fn (array $body) => new Response(200, ['Content-Type' => 'application/json'], json_encode($body)),
            $responses,
        )));
        $stack->push(Middleware::history($this->history));

        return new AnthropicProvider(new Client(
            apiKey: 'sk-ant-test',
            requestOptions: ['transporter' => new Guzzle(['handler' => $stack])],
        ));
    }

    private function body(int $index): array
    {
        return json_decode((string) $this->history[$index]['request']->getBody(), true);
    }

    private function apiMessage(string $stopReason, array $content, int $input = 10, int $output = 5, int $cacheRead = 0, string $model = 'claude-haiku-4-5'): array
    {
        return [
            'id' => 'msg_'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'model' => $model,
            'content' => $content,
            'stop_reason' => $stopReason,
            'stop_sequence' => null,
            'usage' => [
                'input_tokens' => $input,
                'output_tokens' => $output,
                'cache_read_input_tokens' => $cacheRead,
                'cache_creation_input_tokens' => 0,
            ],
        ];
    }
}
