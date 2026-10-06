<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Llm\LlmResult;
use Illuminate\Database\Eloquent\Model;

class UsageRecord extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'period', 'conversations', 'llm_calls', 'rule_replies',
        'input_tokens', 'output_tokens', 'cost_usd',
    ];

    protected function casts(): array
    {
        return ['cost_usd' => 'float'];
    }

    public static function forTenant(int $tenantId, ?string $period = null): self
    {
        return static::withoutGlobalScope('tenant')->firstOrCreate([
            'tenant_id' => $tenantId,
            'period' => $period ?? now()->format('Y-m'),
        ]);
    }

    /**
     * Suma tokens y costo de una llamada al LLM de forma atómica.
     */
    public static function recordLlm(int $tenantId, LlmResult $result): void
    {
        $record = static::forTenant($tenantId);

        static::withoutGlobalScopes()->whereKey($record->id)->incrementEach([
            'llm_calls' => $result->calls,
            'input_tokens' => $result->inputTokens + $result->cacheReadTokens + $result->cacheWriteTokens,
            'output_tokens' => $result->outputTokens,
            'cost_usd' => round($result->costUsd(), 6),
        ]);
    }
}
