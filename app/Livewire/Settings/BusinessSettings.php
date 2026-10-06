<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ScopedToTenant;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tu negocio')]
class BusinessSettings extends Component
{
    use ScopedToTenant;

    public const DAYS = ['mon' => 'Lunes', 'tue' => 'Martes', 'wed' => 'Miércoles', 'thu' => 'Jueves', 'fri' => 'Viernes', 'sat' => 'Sábado', 'sun' => 'Domingo'];

    public const TIMEZONES = [
        'America/Bogota' => 'Colombia',
        'America/Mexico_City' => 'México (centro)',
        'America/Lima' => 'Perú',
        'America/Guayaquil' => 'Ecuador',
        'America/Santiago' => 'Chile',
        'America/Argentina/Buenos_Aires' => 'Argentina',
        'Europe/Madrid' => 'España (península)',
    ];

    public string $name = '';

    public string $timezone = 'America/Bogota';

    /** @var array<string, string> */
    public array $profile = ['direccion' => '', 'telefono' => '', 'web' => '', 'correo' => ''];

    /** @var array<string, array{open: bool, from: string, to: string}> */
    public array $hours = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $tenant = $this->tenant()->fresh();
        $this->name = $tenant->name;
        $this->timezone = $tenant->timezone;
        $this->profile = [...$this->profile, ...array_map('strval', $tenant->profile ?? [])];

        foreach (self::DAYS as $key => $label) {
            $range = ($tenant->business_hours ?? [])[$key] ?? null;
            $this->hours[$key] = ['open' => (bool) $range, 'from' => $range[0] ?? '08:00', 'to' => $range[1] ?? '18:00'];
        }
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'in:'.implode(',', array_keys(self::TIMEZONES))],
            'profile.direccion' => ['nullable', 'string', 'max:200'],
            'profile.telefono' => ['nullable', 'string', 'max:40'],
            'profile.web' => ['nullable', 'string', 'max:200'],
            'profile.correo' => ['nullable', 'email', 'max:120'],
            'hours.*.from' => ['required', 'date_format:H:i'],
            'hours.*.to' => ['required', 'date_format:H:i'],
        ], ['profile.correo.email' => 'Revisa el correo.']);

        foreach ($this->hours as $key => $day) {
            if ($day['open'] && $day['from'] >= $day['to']) {
                $this->addError("hours.$key.to", self::DAYS[$key].': la hora de cierre debe ser después de la apertura.');

                return;
            }
        }

        $this->tenant()->update([
            'name' => trim($this->name),
            'timezone' => $this->timezone,
            'profile' => array_filter(array_map('trim', $this->profile)),
            'business_hours' => collect($this->hours)
                ->filter(fn ($day) => $day['open'])
                ->map(fn ($day) => [$day['from'], $day['to']])
                ->all() ?: null,
        ]);

        $this->notice = 'Datos del negocio guardados.';
    }

    public function render()
    {
        return view('livewire.settings.business', ['days' => self::DAYS, 'timezones' => self::TIMEZONES]);
    }
}
