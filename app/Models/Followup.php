<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Followup extends Model
{
    use BelongsToTenant;

    public const NUDGE = 'nudge';

    public const REMINDER = 'reminder';

    protected $fillable = [
        'tenant_id', 'contact_id', 'conversation_id', 'appointment_id', 'message_id', 'kind', 'anchor', 'status', 'reason',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
