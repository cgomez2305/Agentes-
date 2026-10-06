<?php

namespace App\Services\Llm;

final class LlmResult
{
    /**
     * @param  list<array{name: string, input: array<string, mixed>, output: string}>  $toolCalls
     */
    public function __construct(
        public string $text,
        public string $model,
        public string $stopReason,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $calls = 1,
        public array $toolCalls = [],
    ) {}

    public function refused(): bool
    {
        return $this->stopReason === 'refusal';
    }

    /**
     * Costo en USD según la tabla de precios de config/agentes.php.
     */
    public function costUsd(): float
    {
        $price = config("agentes.llm.pricing.{$this->model}");

        if ($price === null) {
            return 0.0;
        }

        return ($this->inputTokens * $price['input']
            + $this->outputTokens * $price['output']
            + $this->cacheReadTokens * $price['cache_read']
            + $this->cacheWriteTokens * $price['cache_write']) / 1_000_000;
    }
}
