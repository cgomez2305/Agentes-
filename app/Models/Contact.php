<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'wa_id', 'name', 'stage', 'tags', 'lead_data', 'opted_out'];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'lead_data' => 'array',
            'opted_out' => 'boolean',
        ];
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
