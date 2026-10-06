<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Agenda;
use App\Models\Appointment;
use App\Models\CalendarConnection;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class AgendaPageTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 07:00', 'America/Bogota'));
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        $this->tenant->update(['business_hours' => ['mon' => ['08:00', '18:00'], 'tue' => ['08:00', '18:00']]]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_the_page_shows_the_week_with_its_appointments(): void
    {
        $this->appointment('2026-10-13 10:00', 'Valentina Ríos');

        $this->actingAs($this->user)->get('/agenda')
            ->assertOk()
            ->assertSee('12 – 18 de octubre 2026')
            ->assertSee('Valentina Ríos')
            ->assertSee('10:00 am');
    }

    public function test_staff_book_an_appointment_for_a_new_contact(): void
    {
        Livewire::actingAs($this->user)->test(Agenda::class)
            ->call('openForm', '2026-10-13')
            ->set('form.phone', '300 111 2233')
            ->set('form.name', 'Laura Gómez')
            ->set('form.time', '09:00')
            ->call('book')
            ->assertHasNoErrors()
            ->assertSet('showForm', false)
            ->assertSet('notice', fn ($notice) => str_contains($notice, 'Laura Gómez'));

        $appointment = app(TenantContext::class)->run($this->tenant, fn () => Appointment::with('contact')->first());
        $this->assertSame('573001112233', $appointment->contact->wa_id);
        $this->assertSame('human', $appointment->source);
        $this->assertSame('2026-10-13 14:00:00', $appointment->getRawOriginal('starts_at'));
    }

    public function test_booking_errors_are_shown_on_the_form(): void
    {
        $this->appointment('2026-10-13 09:00', 'Otra persona');

        Livewire::actingAs($this->user)->test(Agenda::class)
            ->call('openForm', '2026-10-13')
            ->set('form.phone', '+57 300 111 2233')
            ->set('form.time', '09:00')
            ->call('book')
            ->assertHasErrors(['form.time']);
    }

    public function test_appointments_can_be_completed_or_cancelled(): void
    {
        $first = $this->appointment('2026-10-13 09:00', 'Laura');
        $second = $this->appointment('2026-10-13 11:00', 'Pedro');

        Livewire::actingAs($this->user)->test(Agenda::class)
            ->call('setStatus', $first->id, Appointment::COMPLETED)
            ->call('setStatus', $second->id, Appointment::CANCELLED);

        $this->assertSame(Appointment::COMPLETED, $first->fresh()->status);
        $this->assertSame(Appointment::CANCELLED, $second->fresh()->status);
    }

    public function test_settings_turn_the_agent_booking_off(): void
    {
        Livewire::actingAs($this->user)->test(Agenda::class)
            ->set('settings.enabled', false)
            ->set('settings.capacity', 2)
            ->call('saveSettings')
            ->assertHasNoErrors();

        $tenant = $this->tenant->fresh();
        $this->assertFalse($tenant->booking('enabled'));
        $this->assertSame(2, $tenant->booking('capacity'));
    }

    public function test_appointments_of_other_businesses_are_not_reachable(): void
    {
        $other = $this->makeTenant('Inmobiliaria Horizonte', '444555666');
        $foreign = app(TenantContext::class)->run($other, fn () => $this->appointment('2026-10-13 09:00', 'Ajeno', $other));

        Livewire::actingAs($this->user)->test(Agenda::class)
            ->call('setStatus', $foreign->id, Appointment::CANCELLED)
            ->assertStatus(404);

        $this->assertSame(Appointment::CONFIRMED, $foreign->fresh()->status);
    }

    public function test_google_callback_requires_the_session_state(): void
    {
        config(['services.google.client_id' => 'id', 'services.google.client_secret' => 'secret']);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'a', 'refresh_token' => 'r', 'expires_in' => 3600])]);

        $this->actingAs($this->user)->get('/agenda/google/callback?state=falso&code=x')->assertRedirect('/agenda');
        $this->assertSame(0, CalendarConnection::withoutGlobalScopes()->count());

        $this->actingAs($this->user)->get('/agenda/google/conectar')->assertRedirectContains('accounts.google.com');
        $state = session('google_calendar_state');

        $this->actingAs($this->user)->get("/agenda/google/callback?state={$state}&code=abc")->assertRedirect('/agenda');
        $connection = CalendarConnection::withoutGlobalScopes()->first();
        $this->assertSame($this->tenant->id, $connection->tenant_id);
        $this->assertSame('r', $connection->refresh_token);
    }

    private function appointment(string $localStart, string $name, ?Tenant $tenant = null): Appointment
    {
        $tenant ??= $this->tenant;

        return app(TenantContext::class)->run($tenant, function () use ($localStart, $name) {
            $start = CarbonImmutable::parse($localStart, 'America/Bogota');

            return Appointment::create([
                'contact_id' => Contact::create(['wa_id' => '57300'.random_int(1000000, 9999999), 'name' => $name])->id,
                'title' => 'Valoración',
                'starts_at' => $start->utc(),
                'ends_at' => $start->addMinutes(30)->utc(),
            ]);
        });
    }
}
