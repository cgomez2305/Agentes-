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

    /** Estados que no forman parte de la conversación enviada (no van al contexto del LLM). */
    public const UNSENT_STATUSES = ['draft', 'discarded'];

    protected $fillable = [
        'tenant_id', 'conversation_id', 'direction', 'author', 'user_id', 'type', 'content',
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

    protected static function booted(): void
    {
        // Un mensaje siempre pertenece al negocio de su conversación.
        static::creating(function (Message $message) {
            $message->tenant_id ??= Conversation::withoutGlobalScope('tenant')->whereKey($message->conversation_id)->value('tenant_id');
        });

        static::created(function (Message $message) {
            if (! in_array($message->status, self::UNSENT_STATUSES, true)) {
                Conversation::withoutGlobalScope('tenant')
                    ->whereKey($message->conversation_id)
                    ->update(['last_message_at' => $message->created_at]);
            }
        });
    }

    /**
     * Contenido escapado con el formato de WhatsApp (*negrita*, _cursiva_).
     */
    public function htmlBody(): string
    {
        $html = e((string) $this->content);
        $html = preg_replace('/\*(\S(?:.*?\S)?)\*/s', '<b>$1</b>', $html);
        $html = preg_replace('/(?<![\w])_(\S(?:.*?\S)?)_(?![\w])/s', '<i>$1</i>', $html);

        return nl2br($html, false);
    }

    public function authorLabel(): string
    {
        return match (true) {
            $this->direction === self::IN => 'Cliente',
            $this->author === 'human' => $this->user?->name ?? 'Equipo',
            $this->source === 'llm' => 'Agente IA',
            default => 'Respuesta automática',
        };
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
