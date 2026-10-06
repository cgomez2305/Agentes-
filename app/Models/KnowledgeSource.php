<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeSource extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'type', 'title', 'origin', 'status'];

    public function chunks(): HasMany
    {
        return $this->hasMany(KnowledgeChunk::class);
    }
}
