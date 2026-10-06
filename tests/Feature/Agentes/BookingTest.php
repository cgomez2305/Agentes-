<?php

namespace Tests\Feature\Agentes;

use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\CatalogItem;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\Agent\AgentRuntime;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Onboarding\TenantProvisioner;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        // Lunes 12 de octubre de 2026, 7:00 a. m. en Bogotá.
        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:00', 'America/Bogota'));
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $this->fakeLlm();

        $this->tenant = $this->makeTenant();
        $this->tenant->update([
            'business_hours' => ['mon' => ['08:00', '12:00'], 'tue' => ['08:00', '12:00']],
            'booking_settings' => ['enabled' => true, 'slot_minutes' => 60, 'default_duration' => 60, 'min_notice_hours' => 2],
        ]);
        app(TenantContext::class)->set($this->tenant);
        $this->contact = Contact::create(['wa_id' => '573001112233', 'name' => 'Laura']);
    }

    public function test_slots_respect_hours_notice_and_existing_appointments(): void
    {
        $this->book('2026-10-12 10:00');

        $slots = $this->service()->availableSlots($this->tenant->fresh(), 60);

        // 08:00 queda antes del aviso mínimo de 2 h; 10:00 está ocupado; el miércoles está cerrado.
        $this->assertSame(['2026-10-12', '2026-10-13', '2026-10-19'], $slots->keys()->all());
        $this->assertSame(['09:00', '11:00'], collect($slots['2026-10-12'])->map->format('H:i')->all());
        $this->assertSame(['08:00', '09:00', '10:00', '11:00'], collect($slots['2026-10-13'])->map->format('H:i')->all());
    }

    public function test_service_duration_changes_the_slots(): void
    {
        CatalogItem::create(['name' => 'Ortodoncia valoración', 'price' => 80000, 'duration_minutes' => 120]);

        $slots = $this->service()->availableSlots($this->tenant->fresh(), 120);

        $this->assertSame(['09:00', '10:00'], collect($slots['2026-10-12'])->map->format('H:i')->all());
    }

    public function test_double_booking_and_out_of_hours_are_rejected(): void
    {
        $this->book('2026-10-13 09:00');

        $this->assertBookingFails('2026-10-13 09:30', 'ocupar');
        $this->assertBookingFails('2026-10-13 11:30', 'fuera del horario');
        $this->assertBookingFails('2026-10-12 07:30', 'anticipación');
        $this->assertBookingFails('2026-10-14 09:00', 'fuera del horario');
    }

    public function test_capacity_allows_parallel_appointments(): void
    {
        $this->tenant->update(['booking_settings' => [...$this->tenant->booking_settings, 'capacity' => 2]]);
        $this->book('2026-10-13 09:00');
        $other = Contact::create(['wa_id' => '573009998877']);

        $this->service()->book($this->tenant->fresh(), $other, CarbonImmutable::parse('2026-10-13 09:00', 'America/Bogota'));

        $this->assertSame(2, Appointment::count());
    }

    public function test_the_agent_checks_availability_and_books(): void
    {
        $this->llm->push('Tengo libre el martes a las 8:00 o 9:00. ¿Cuál prefieres?', [
            ['name' => 'consultar_disponibilidad', 'input' => ['fecha' => '2026-10-13', 'franja' => 'manana', 'servicio' => 'limpieza']],
        ]);
        $this->llm->push('¡Listo, Laura! Quedó tu cita el martes 13 a las 9:00 a. m.', [
            ['name' => 'crear_cita', 'input' => ['fecha_hora' => '2026-10-13 09:00', 'servicio' => 'limpieza', 'notas' => 'Primera vez']],
        ]);

        $conversation = $this->conversation('Quiero agendar una limpieza el martes');
        app(AgentRuntime::class)->handle($conversation);

        $tools = collect($this->llm->requests[0]->tools)->pluck('name');
        $this->assertTrue($tools->contains('crear_cita'));
        $availability = $conversation->messages()->latest('id')->first()->meta['tool_calls'][0]['output'];
        $this->assertStringContainsString('martes 13 de octubre (2026-10-13): 08:00, 09:00, 10:00', $availability);
        $this->assertStringContainsString('Servicio: Limpieza dental profunda (45 min)', $availability);

        $conversation->messages()->create(['direction' => 'in', 'content' => 'A las 9 porfa', 'status' => 'received']);
        app(AgentRuntime::class)->handle($conversation->fresh());

        $appointment = Appointment::first();
        $this->assertSame('Limpieza dental profunda', $appointment->title);
        $this->assertSame('2026-10-13 09:00', $appointment->starts_at->setTimezone('America/Bogota')->format('Y-m-d H:i'));
        $this->assertSame(45, (int) $appointment->starts_at->diffInMinutes($appointment->ends_at));
        $this->assertSame('Primera vez', $appointment->notes);
        $this->assertSame('calificado', $this->contact->fresh()->stage);

        // La siguiente vez el agente ve la cita en el contexto.
        app(AgentRuntime::class)->handle($this->conversation('¿A qué hora era mi cita?', $conversation));
        $this->assertStringContainsString('Próxima cita del cliente: Limpieza dental profunda, martes 13 de octubre a las 9:00 a. m.', $this->llm->requests[2]->context);
    }

    public function test_agenda_tools_are_hidden_when_booking_is_disabled(): void
    {
        $this->tenant->update(['booking_settings' => ['enabled' => false]]);

        app(AgentRuntime::class)->handle($this->conversation('Quiero una cita'));

        $tools = collect($this->llm->requests[0]->tools)->pluck('name');
        $this->assertFalse($tools->contains('crear_cita'));
        $this->assertStringContainsString('No agendas citas directamente', $this->llm->requests[0]->system);
    }

    public function test_google_calendar_busy_blocks_slots_and_new_appointments_are_synced(): void
    {
        CalendarConnection::create(['access_token' => 'g-token', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        Http::fake([
            'www.googleapis.com/calendar/v3/freeBusy' => Http::response(['calendars' => ['primary' => ['busy' => [
                ['start' => '2026-10-13T13:00:00Z', 'end' => '2026-10-13T15:00:00Z'], // 08:00-10:00 en Bogotá
            ]]]]),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => Http::response(['id' => 'evt_123']),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
        ]);

        $slots = $this->service()->availableSlots($this->tenant->fresh(), 60);
        $this->assertSame(['10:00', '11:00'], collect($slots['2026-10-13'])->map->format('H:i')->all());

        $this->assertBookingFails('2026-10-13 09:00', 'calendario del negocio');

        $appointment = $this->book('2026-10-13 10:00');
        $this->assertSame('evt_123', $appointment->fresh()->external_event_id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/events') && $r['start']['timeZone'] === 'America/Bogota'
            && str_contains($r['summary'], 'Laura') && $r->hasHeader('Authorization', 'Bearer g-token'));
    }

    public function test_a_freshly_created_tenant_uses_its_timezone(): void
    {
        $tenant = app(TenantProvisioner::class)->create('Nueva Clínica', 'clinica');
        $this->assertSame('America/Bogota', $tenant->timezone);
        $this->assertSame('America/Bogota', (new Tenant(['name' => 'X', 'slug' => 'x']))->timezone);
    }

    private function service(): BookingService
    {
        return app(BookingService::class);
    }

    private function book(string $localTime): Appointment
    {
        return $this->service()->book($this->tenant->fresh(), $this->contact, CarbonImmutable::parse($localTime, 'America/Bogota'));
    }

    private function assertBookingFails(string $localTime, string $reason): void
    {
        try {
            $this->book($localTime);
            $this->fail("Se esperaba que la reserva de {$localTime} fallara.");
        } catch (BookingException $e) {
            $this->assertStringContainsString($reason, $e->getMessage());
        }
    }

    private function conversation(string $text, ?Conversation $existing = null): Conversation
    {
        $conversation = $existing ?? Conversation::create([
            'contact_id' => $this->contact->id,
            'channel_id' => $this->tenant->channels()->first()->id,
            'agent_id' => $this->tenant->agent->id,
            'window_expires_at' => now()->addDay(),
        ]);
        $conversation->messages()->create(['direction' => 'in', 'content' => $text, 'status' => 'received']);

        return $conversation->fresh();
    }
}
