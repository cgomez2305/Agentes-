<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Appointment extends Model
{
    use BelongsToTenant;

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    public const COMPLETED = 'completed';

    public const NO_SHOW = 'no_show';

    public const STATUS_LABELS = [
        self::CONFIRMED => 'Confirmada',
        self::COMPLETED => 'Atendida',
        self::NO_SHOW => 'No asistió',
        self::CANCELLED => 'Cancelada',
    ];

    protected $fillable = [
        'tenant_id', 'contact_id', 'conversation_id', 'catalog_item_id', 'title', 'starts_at', 'ends_at',
        'status', 'source', 'notes', 'external_event_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(CatalogItem::class, 'catalog_item_id');
    }

    /**
     * Citas activas que se cruzan con el intervalo [desde, hasta).
     */
    public function scopeOverlapping(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->where('status', self::CONFIRMED)
            ->where('starts_at', '<', $to)
            ->where('ends_at', '>', $from);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('status', self::CONFIRMED)->where('starts_at', '>=', now())->orderBy('starts_at');
    }
}
