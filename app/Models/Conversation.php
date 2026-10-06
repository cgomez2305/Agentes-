<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    use BelongsToTenant;

    public const STATUS_BOT = 'bot';

    public const STATUS_HUMAN = 'human';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'tenant_id', 'contact_id', 'channel_id', 'agent_id', 'status', 'summary',
        'handoff_reason', 'last_inbound_at', 'window_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'last_inbound_at' => 'datetime',
            'window_expires_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isWindowOpen(): bool
    {
        return $this->window_expires_at !== null && $this->window_expires_at->isFuture();
    }

    public function handToHuman(string $reason): void
    {
        $this->update(['status' => self::STATUS_HUMAN, 'handoff_reason' => $reason]);
    }
}
