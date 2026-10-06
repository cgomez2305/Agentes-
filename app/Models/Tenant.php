<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Tenant extends Model
{
    protected $fillable = [
        'name', 'slug', 'vertical', 'plan', 'monthly_conversation_limit',
        'timezone', 'locale', 'business_hours', 'booking_settings', 'profile',
    ];

    /** Mismos valores por defecto que la base de datos, para que existan antes de recargar el modelo. */
    protected $attributes = [
        'plan' => 'prueba',
        'monthly_conversation_limit' => 500,
        'timezone' => 'America/Bogota',
        'locale' => 'es_CO',
    ];

    /** Valores por defecto de la agenda; cada negocio puede sobrescribirlos. */
    public const BOOKING_DEFAULTS = [
        'enabled' => false,
        'slot_minutes' => 30,
        'default_duration' => 30,
        'min_notice_hours' => 2,
        'max_days_ahead' => 30,
        'capacity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'booking_settings' => 'array',
            'profile' => 'array',
            'monthly_conversation_limit' => 'integer',
        ];
    }

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class);
    }

    public function agent(): HasOne
    {
        return $this->hasOne(Agent::class)->where('is_active', true)->latestOfMany();
    }

    public function calendarConnection(): HasOne
    {
        return $this->hasOne(CalendarConnection::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * Configuración de la agenda con los valores por defecto completados.
     */
    public function booking(string $key): mixed
    {
        return ($this->booking_settings ?? [])[$key] ?? self::BOOKING_DEFAULTS[$key];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * ¿El negocio está abierto en el instante dado (en su zona horaria)?
     * Sin horario configurado se considera abierto siempre.
     */
    public function isOpenAt(CarbonInterface $moment): bool
    {
        $hours = $this->business_hours;

        if (empty($hours)) {
            return true;
        }

        $local = $moment->copy()->setTimezone($this->timezone);
        $day = strtolower($local->format('D')); // mon, tue, ...

        if (empty($hours[$day])) {
            return false;
        }

        [$open, $close] = $hours[$day];
        $now = $local->format('H:i');

        return $now >= $open && $now < $close;
    }

    public function describeBusinessHours(): string
    {
        if (empty($this->business_hours)) {
            return 'Atención todos los días.';
        }

        $names = [
            'mon' => 'lunes', 'tue' => 'martes', 'wed' => 'miércoles', 'thu' => 'jueves',
            'fri' => 'viernes', 'sat' => 'sábado', 'sun' => 'domingo',
        ];

        $lines = [];
        foreach ($names as $key => $label) {
            $range = $this->business_hours[$key] ?? null;
            $lines[] = $range ? "{$label}: {$range[0]} a {$range[1]}" : "{$label}: cerrado";
        }

        return implode('; ', $lines).'.';
    }
}
