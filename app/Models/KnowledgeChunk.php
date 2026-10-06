<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeChunk extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'knowledge_source_id', 'content', 'search_text', 'position'];

    protected static function booted(): void
    {
        static::saving(function (KnowledgeChunk $chunk) {
            $chunk->search_text = Text::normalize($chunk->content);
        });
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSource::class, 'knowledge_source_id');
    }
}
