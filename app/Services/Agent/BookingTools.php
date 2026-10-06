<?php

namespace App\Services\Agent;

use App\Models\Appointment;
use App\Models\Conversation;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Llm\ToolDefinition;
use Carbon\CarbonImmutable;

/**
 * Herramientas de agenda. Solo se ofrecen al modelo si el negocio la tiene activa.
 */
class BookingTools
{
    public const NAMES = ['consultar_disponibilidad', 'crear_cita', 'cancelar_cita'];

    public function __construct(
        private readonly Conversation $conversation,
        private readonly BookingService $booking,
        private readonly KnowledgeSearch $search,
    ) {}

    public function enabled(): bool
    {
        return (bool) $this->conversation->tenant->booking('enabled');
    }

    /**
     * @return list<ToolDefinition>
     */
    public function definitions(): array
    {
        return [
            new ToolDefinition(
                name: 'consultar_disponibilidad',
                description: 'Devuelve los horarios libres para agendar, agrupados por día. Úsala antes de proponer '
                    .'horarios: nunca inventes disponibilidad.',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'fecha' => ['type' => 'string', 'description' => 'Día desde el que buscar, formato AAAA-MM-DD. Omítelo para buscar desde hoy.'],
                        'servicio' => ['type' => 'string', 'description' => 'Servicio a agendar, para usar su duración.'],
                        'franja' => ['type' => 'string', 'enum' => ['manana', 'tarde', 'cualquiera']],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            new ToolDefinition(
                name: 'crear_cita',
                description: 'Agenda la cita en un horario que devolvió consultar_disponibilidad. Úsala solo cuando el '
                    .'cliente confirmó día y hora. La cita existe únicamente si esta herramienta responde "Cita creada".',
                parameters: [
                    'type' => 'object',
                    'properties' => [
                        'fecha_hora' => ['type' => 'string', 'description' => 'Inicio en hora local del negocio, formato AAAA-MM-DD HH:MM (24 h).'],
                        'servicio' => ['type' => 'string', 'description' => 'Servicio del catálogo, si aplica.'],
                        'nombre' => ['type' => 'string', 'description' => 'Nombre de la persona que asiste.'],
                        'notas' => ['type' => 'string', 'description' => 'Motivo o detalles útiles para el equipo.'],
                    ],
                    'required' => ['fecha_hora'],
                    'additionalProperties' => false,
                ],
            ),
            new ToolDefinition(
                name: 'cancelar_cita',
                description: 'Cancela la próxima cita del cliente. Confirma con el cliente antes de usarla. Para '
                    .'reprogramar: cancela y luego crea la nueva cita.',
                parameters: ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
            ),
        ];
    }

    public function execute(string $name, array $input): string
    {
        try {
            return match ($name) {
                'consultar_disponibilidad' => $this->availability($input),
                'crear_cita' => $this->create($input),
                'cancelar_cita' => $this->cancel(),
            };
        } catch (BookingException $e) {
            return 'No se pudo: '.$e->getMessage().' Ofrece otro horario con consultar_disponibilidad.';
        }
    }

    private function availability(array $input): string
    {
        $tenant = $this->conversation->tenant;
        $service = filled($input['servicio'] ?? null) ? $this->search->catalog($input['servicio'], 1)->first() : null;
        $duration = $service?->duration_minutes ?: $tenant->booking('default_duration');
        $from = $this->parseDate($input['fecha'] ?? null);
        $band = $input['franja'] ?? 'cualquiera';

        $days = $this->booking->availableSlots($tenant, $duration, $from, days: 3, perDay: 40)
            ->map(fn (array $slots) => array_values(array_filter($slots, fn (CarbonImmutable $s) => match ($band) {
                'manana' => $s->hour < 12,
                'tarde' => $s->hour >= 12,
                default => true,
            })))
            ->filter()
            ->map(fn (array $slots) => array_slice($slots, 0, 8));

        if ($days->isEmpty()) {
            return 'No hay horarios libres en esos días. Ofrece buscar otra fecha o pasar con un asesor.';
        }

        $lines = [($service ? "Servicio: {$service->name} ({$duration} min)." : "Duración: {$duration} min.")];
        foreach ($days as $date => $slots) {
            $day = CarbonImmutable::parse($date, $tenant->timezone)->locale('es');
            $lines[] = '- '.$day->isoFormat('dddd D [de] MMMM')." ({$date}): ".collect($slots)->map->format('H:i')->implode(', ');
        }
        $lines[] = 'Propón 2 o 3 opciones; para agendar usa crear_cita con fecha_hora "AAAA-MM-DD HH:MM".';

        return implode("\n", $lines);
    }

    private function create(array $input): string
    {
        $tenant = $this->conversation->tenant;
        $contact = $this->conversation->contact;

        $existing = Appointment::query()->upcoming()->where('contact_id', $contact->id)->first();
        if ($existing) {
            return 'El cliente ya tiene una cita el '.$this->describe($existing).'. Si quiere cambiarla, confirma y usa cancelar_cita antes de crear la nueva.';
        }

        $start = $this->parseDateTime((string) ($input['fecha_hora'] ?? ''));
        $service = filled($input['servicio'] ?? null) ? $this->search->catalog($input['servicio'], 1)->first() : null;

        if (filled($input['nombre'] ?? null) && ! $contact->name) {
            $contact->update(['name' => $input['nombre']]);
        }

        $appointment = $this->booking->book($tenant, $contact, $start, $service, $this->conversation, notes: $input['notas'] ?? null);

        if ($contact->stage === 'nuevo') {
            $contact->update(['stage' => 'calificado']);
        }

        return "Cita creada: {$appointment->title}, ".$this->describe($appointment).'. Confírmale al cliente día, hora y dirección.';
    }

    private function cancel(): string
    {
        $appointment = Appointment::query()->upcoming()->where('contact_id', $this->conversation->contact_id)->first();

        if (! $appointment) {
            return 'El cliente no tiene citas próximas.';
        }

        $this->booking->cancel($this->conversation->tenant, $appointment);

        return 'Cita cancelada: '.$appointment->title.', '.$this->describe($appointment).'.';
    }

    private function describe(Appointment $appointment): string
    {
        return CarbonImmutable::instance($appointment->starts_at)
            ->setTimezone($this->conversation->tenant->timezone)
            ->locale('es')
            ->isoFormat('dddd D [de] MMMM [a las] h:mm a');
    }

    private function parseDate(?string $value): ?CarbonImmutable
    {
        if (blank($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value, $this->conversation->tenant->timezone) ?: null;
    }

    /**
     * @throws BookingException
     */
    private function parseDateTime(string $value): CarbonImmutable
    {
        $value = str_replace('T', ' ', trim($value));
        $parsed = preg_match('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}$/', $value)
            ? CarbonImmutable::createFromFormat('Y-m-d G:i', $value, $this->conversation->tenant->timezone)
            : false;

        if (! $parsed) {
            throw new BookingException('La fecha y hora deben ir en formato AAAA-MM-DD HH:MM.');
        }

        return $parsed;
    }
}
