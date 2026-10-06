<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Text;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'kind', 'sku', 'name', 'description', 'price', 'currency',
        'duration_minutes', 'is_available',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'duration_minutes' => 'integer',
            'is_available' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CatalogItem $item) {
            $item->search_text = Text::normalize($item->name.' '.$item->description);
        });
    }

    public function formattedPrice(): ?string
    {
        if ($this->price === null) {
            return null;
        }

        return match ($this->currency) {
            'COP' => '$'.number_format($this->price, 0, ',', '.').' COP',
            default => number_format($this->price / 100, 2, ',', '.').' '.$this->currency,
        };
    }
}
