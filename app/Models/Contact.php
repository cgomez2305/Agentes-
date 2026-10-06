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

    /**
     * Teléfono legible: 573001112233 -> +57 300 111 2233.
     */
    public function displayPhone(): string
    {
        if (preg_match('/^57(\d{3})(\d{3})(\d{4})$/', $this->wa_id, $m)) {
            return "+57 {$m[1]} {$m[2]} {$m[3]}";
        }

        return '+'.$this->wa_id;
    }

    public function displayName(): string
    {
        return $this->name ?: $this->displayPhone();
    }

    public function initials(): string
    {
        if (! $this->name) {
            return '#';
        }

        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
