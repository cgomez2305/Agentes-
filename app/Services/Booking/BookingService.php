<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disponibilidad y reservas. La fuente de verdad son las citas guardadas
 * aquí; si el negocio conectó Google Calendar, sus eventos también ocupan
 * espacio y cada cita nueva se copia allá.
 */
class BookingService
{
    /** Horario usado para agendar si el negocio no configuró uno. */
    private const FALLBACK_HOURS = [
        'mon' => ['08:00', '18:00'], 'tue' => ['08:00', '18:00'], 'wed' => ['08:00', '18:00'],
        'thu' => ['08:00', '18:00'], 'fri' => ['08:00', '18:00'],
    ];

    public function __construct(private readonly GoogleCalendar $google) {}

    /**
     * Horarios libres agrupados por día (en la zona horaria del negocio).
     *
     * @return Collection<string, list<CarbonImmutable>> clave: fecha Y-m-d
     */
    public function availableSlots(Tenant $tenant, int $durationMinutes, ?CarbonImmutable $fromDay = null, int $days = 3, int $perDay = 8): Collection
    {
        $tz = $tenant->timezone;
        $now = CarbonImmutable::now($tz);
        $earliest = $now->addHours($tenant->booking('min_notice_hours'));
        $lastDay = $now->startOfDay()->addDays($tenant->booking('max_days_ahead'));
        $day = ($fromDay ?? $now)->setTimezone($tz)->startOfDay();
        if ($day->lessThan($now->startOfDay())) {
            $day = $now->startOfDay();
        }

        $rangeEnd = $day->addDays(31);
        $taken = $this->takenIntervals($tenant, $day, $rangeEnd);
        $step = $tenant->booking('slot_minutes');
        $capacity = max(1, (int) $tenant->booking('capacity'));

        $result = collect();
        for ($i = 0; $i < 31 && $result->count() < $days && $day->lessThanOrEqualTo($lastDay); $i++, $day = $day->addDay()) {
            $slots = [];
            foreach ($this->openingRanges($tenant, $day) as [$open, $close]) {
                for ($start = $open; $start->addMinutes($durationMinutes)->lessThanOrEqualTo($close); $start = $start->addMinutes($step)) {
                    if ($start->lessThan($earliest)) {
                        continue;
                    }

                    $end = $start->addMinutes($durationMinutes);
                    if ($this->countOverlaps($taken['appointments'], $start, $end) < $capacity
                        && $this->countOverlaps($taken['external'], $start, $end) === 0) {
                        $slots[] = $start;
                    }
                }
            }

            if ($slots !== []) {
                $result->put($day->format('Y-m-d'), array_slice($slots, 0, $perDay));
            }
        }

        return $result;
    }

    /**
     * Crea la cita si el horario sigue libre.
     *
     * @throws BookingException
     */
    public function book(Tenant $tenant, Contact $contact, CarbonImmutable $start, ?CatalogItem $service = null, ?Conversation $conversation = null, string $source = 'agent', ?string $notes = null): Appointment
    {
        $duration = $service?->duration_minutes ?: $tenant->booking('default_duration');
        $start = $start->setTimezone($tenant->timezone);
        $end = $start->addMinutes($duration);

        $this->assertBookable($tenant, $start, $end);

        $appointment = DB::transaction(function () use ($tenant, $contact, $start, $end, $service, $conversation, $source, $notes) {
            // Serializa las reservas del negocio: dos clientes no pueden tomar el mismo hueco a la vez.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();
            $overlaps = Appointment::query()->overlapping($start->utc(), $end->utc())->count();

            if ($overlaps >= max(1, (int) $tenant->booking('capacity'))) {
                throw new BookingException('Ese horario se acaba de ocupar.');
            }

            return Appointment::create([
                'contact_id' => $contact->id,
                'conversation_id' => $conversation?->id,
                'catalog_item_id' => $service?->id,
                'title' => $service?->name ?? 'Cita',
                'starts_at' => $start->utc(),
                'ends_at' => $end->utc(),
                'source' => $source,
                'notes' => $notes,
            ]);
        });

        $this->syncCreate($tenant, $appointment);

        return $appointment;
    }

    public function cancel(Tenant $tenant, Appointment $appointment): void
    {
        $appointment->update(['status' => Appointment::CANCELLED]);

        $connection = $tenant->calendarConnection;
        if ($connection && $appointment->external_event_id) {
            try {
                $this->google->deleteEvent($connection, $appointment->external_event_id);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @throws BookingException
     */
    private function assertBookable(Tenant $tenant, CarbonImmutable $start, CarbonImmutable $end): void
    {
        $now = CarbonImmutable::now($tenant->timezone);

        if ($start->lessThan($now->addHours($tenant->booking('min_notice_hours')))) {
            throw new BookingException("Las citas se agendan con al menos {$tenant->booking('min_notice_hours')} horas de anticipación.");
        }

        if ($start->greaterThan($now->addDays($tenant->booking('max_days_ahead')))) {
            throw new BookingException("Solo se agenda hasta {$tenant->booking('max_days_ahead')} días adelante.");
        }

        $insideHours = collect($this->openingRanges($tenant, $start->startOfDay()))
            ->contains(fn ($range) => $start->greaterThanOrEqualTo($range[0]) && $end->lessThanOrEqualTo($range[1]));

        if (! $insideHours) {
            throw new BookingException('Ese horario está fuera del horario de atención.');
        }

        $external = $this->takenIntervals($tenant, $start->startOfDay(), $start->endOfDay())['external'];
        if ($this->countOverlaps($external, $start, $end) > 0) {
            throw new BookingException('Ese horario ya está ocupado en el calendario del negocio.');
        }
    }

    /**
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function openingRanges(Tenant $tenant, CarbonImmutable $day): array
    {
        $hours = $tenant->business_hours ?: self::FALLBACK_HOURS;
        $range = $hours[strtolower($day->format('D'))] ?? null;

        if (! $range) {
            return [];
        }

        [$open, $close] = $range;

        return [[
            $day->setTimeFromTimeString($open),
            $day->setTimeFromTimeString($close),
        ]];
    }

    /**
     * @return array{appointments: list<array>, external: list<array>}
     */
    private function takenIntervals(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $appointments = Appointment::query()
            ->overlapping($from->utc(), $to->utc())
            ->get(['starts_at', 'ends_at'])
            ->map(fn (Appointment $a) => [CarbonImmutable::instance($a->starts_at), CarbonImmutable::instance($a->ends_at)])
            ->all();

        $external = [];
        $connection = $tenant->calendarConnection;
        if ($connection) {
            try {
                // Las citas creadas aquí también están en Google: se excluyen para no contarlas dos veces.
                $ownEvents = Appointment::query()->overlapping($from->utc(), $to->utc())->whereNotNull('external_event_id')
                    ->get(['starts_at', 'ends_at'])
                    ->map(fn ($a) => $a->starts_at->getTimestamp().'-'.$a->ends_at->getTimestamp())
                    ->all();

                $external = array_values(array_filter(
                    $this->google->busy($connection, $from, $to),
                    fn ($slot) => ! in_array($slot[0]->getTimestamp().'-'.$slot[1]->getTimestamp(), $ownEvents, true),
                ));
            } catch (\Throwable $e) {
                Log::warning('Google Calendar no respondió; la disponibilidad usa solo las citas locales.', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            }
        }

        return ['appointments' => $appointments, 'external' => $external];
    }

    private function countOverlaps(array $intervals, CarbonImmutable $start, CarbonImmutable $end): int
    {
        return count(array_filter($intervals, fn ($i) => $i[0]->lessThan($end) && $i[1]->greaterThan($start)));
    }

    private function syncCreate(Tenant $tenant, Appointment $appointment): void
    {
        $connection = $tenant->calendarConnection;

        if (! $connection) {
            return;
        }

        try {
            $appointment->update([
                'external_event_id' => $this->google->createEvent($connection, $appointment->load('contact'), $tenant->timezone),
            ]);
        } catch (\Throwable $e) {
            // La cita queda válida aquí aunque Google falle; se registra para revisar.
            report($e);
        }
    }
}
