<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class Agent extends Model
{
    use BelongsToTenant;

    public const MODE_AUTO = 'auto';

    public const MODE_SUGGEST = 'sugerir';

    protected $fillable = [
        'tenant_id', 'name', 'instructions', 'tone', 'mode', 'model_tier',
        'qualification_fields', 'quick_replies', 'handoff_message', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'qualification_fields' => 'array',
            'quick_replies' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function handoffMessage(): string
    {
        return $this->handoff_message
            ?: 'Te comunico con una persona de nuestro equipo, en breve te responde.';
    }
}
