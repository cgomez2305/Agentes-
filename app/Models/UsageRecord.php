<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
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
}
