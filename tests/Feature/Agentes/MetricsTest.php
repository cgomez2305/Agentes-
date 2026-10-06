<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Metrics;
use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Metrics\TenantMetrics;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00', 'America/Bogota'));
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_computes_resolution_response_time_cost_and_funnel(): void
    {
        // Resuelta por el bot: responde a los 8 s.
        $this->conversation('2026-10-10 09:00', [['in', 0], ['out', 8, 'bot', 'llm', 0.004]], stage: 'calificado');
        // Pasada a humano: responde a los 30 s.
        $this->conversation('2026-10-11 10:00', [['in', 0], ['out', 30, 'bot', 'rule', 0], ['in', 60], ['out', 120, 'human', 'human', 0]], handoff: true);
        // Fuera del periodo de 7 días.
        $this->conversation('2026-09-20 10:00', [['in', 0], ['out', 5, 'bot', 'llm', 0.01]]);

        Appointment::create([
            'contact_id' => Contact::first()->id, 'title' => 'Valoración', 'source' => 'agent',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addMinutes(30),
        ]);

        $m = app(TenantMetrics::class)->compute($this->tenant, CarbonImmutable::parse('2026-10-06', 'America/Bogota'), CarbonImmutable::parse('2026-10-12 23:59', 'America/Bogota'));

        $this->assertSame(2, $m['conversations']);
        $this->assertSame(1, $m['bot_only']);
        $this->assertSame(0.5, $m['bot_only_rate']);
        $this->assertSame(19, $m['first_response_seconds']); // mediana de 8 y 30
        $this->assertSame(['rule' => 1, 'llm' => 1, 'human' => 1], $m['replies']);
        $this->assertEqualsWithDelta(0.004, $m['cost_usd'], 0.000001);
        $this->assertSame(1, $m['appointments_by_agent']);
        $this->assertSame(1, $m['funnel']['qualified']);
        $this->assertCount(7, $m['daily']);
        $this->assertSame(['date' => '2026-10-10', 'label' => '10 oct.', 'bot' => 1, 'human' => 0], $m['daily'][4]);
        $this->assertSame(1, $m['daily'][5]['human']);
    }

    public function test_the_page_renders_with_chart_and_table(): void
    {
        $this->conversation('2026-10-11 10:00', [['in', 0], ['out', 8, 'bot', 'llm', 0.004]]);
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->actingAs($user)->get('/metricas')->assertOk()->assertSee('Conversaciones por día')->assertSee('Solo el agente');

        Livewire::actingAs($user)->test(Metrics::class)
            ->set('days', 7)
            ->assertSet('days', 7)
            ->set('showTable', true)
            ->assertSee('11 oct.');
    }

    /**
     * @param  list<array>  $script  [dirección, segundos, autor, origen, costo]
     */
    private function conversation(string $localStart, array $script, bool $handoff = false, string $stage = 'nuevo'): Conversation
    {
        $start = CarbonImmutable::parse($localStart, 'America/Bogota');
        $contact = Contact::create(['wa_id' => '57300'.random_int(1000000, 9999999), 'stage' => $stage]);
        $contact->forceFill(['created_at' => $start])->save();

        $conversation = Conversation::create(['contact_id' => $contact->id, 'handoff_reason' => $handoff ? 'Pidió un asesor' : null]);
        $conversation->forceFill(['created_at' => $start])->save();

        foreach ($script as $line) {
            [$direction, $seconds, $author, $source, $cost] = $line + [2 => null, 3 => null, 4 => 0];
            $message = new Message([
                'direction' => $direction, 'content' => 'x', 'status' => $direction === 'in' ? 'received' : 'sent',
                'author' => $author ?? 'contact', 'source' => $source ?? null, 'cost_usd' => $cost ?? 0,
            ]);
            $message->conversation_id = $conversation->id;
            $message->created_at = $start->addSeconds($seconds);
            $message->save();
        }

        return $conversation;
    }
}
