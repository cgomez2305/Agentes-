<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    use BelongsToTenant;

    public const IN = 'in';

    public const OUT = 'out';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'direction', 'author', 'type', 'content',
        'wa_message_id', 'status', 'source', 'model', 'input_tokens', 'output_tokens',
        'cost_usd', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'cost_usd' => 'float',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
