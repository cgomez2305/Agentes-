<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Settings\AgentSettings;
use App\Models\Agent;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_business_signs_up_and_lands_on_its_new_agent(): void
    {
        $this->get('/registro')->assertOk()->assertSee('Inmobiliaria o constructora');

        $this->post('/registro', $this->payload())->assertRedirect('/configuracion');

        $user = User::where('email', 'mariana@altosdelrio.co')->first();
        $tenant = $user->tenant;
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Constructora Altos del Río', $tenant->name);
        $this->assertSame('inmobiliaria', $tenant->vertical);
        $this->assertSame('Envigado, Antioquia', $tenant->profile['direccion']);
        $this->assertTrue($tenant->booking('enabled'));

        $agent = app(TenantContext::class)->run($tenant, fn () => Agent::first());
        $this->assertSame('Andrés', $agent->name);

        $this->get('/configuracion')->assertOk()->assertSee('¡Tu agente está creado!')->assertSee('¿Tienen apartamentos para invertir?');
    }

    public function test_validation_requires_consent_unique_email_and_matching_passwords(): void
    {
        User::factory()->create(['email' => 'mariana@altosdelrio.co']);

        $this->post('/registro', $this->payload(['consent' => null, 'password_confirmation' => 'otra-clave']))
            ->assertSessionHasErrors(['consent', 'email', 'password']);

        $this->assertSame(0, Tenant::count());
    }

    public function test_onboarding_steps_follow_the_setup(): void
    {
        $this->post('/registro', $this->payload());
        $user = User::where('email', 'mariana@altosdelrio.co')->first();

        $steps = Livewire::actingAs($user)->test(AgentSettings::class)->instance()->onboarding;
        $this->assertFalse(collect($steps)->firstWhere('label', 'Agrega tu catálogo con precios')['done']);
        $this->assertTrue(collect($steps)->firstWhere('label', 'Completa dirección y horario')['done']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'vertical' => 'inmobiliaria',
            'business' => 'Constructora Altos del Río',
            'address' => 'Envigado, Antioquia',
            'name' => 'Mariana Toro',
            'email' => 'mariana@altosdelrio.co',
            'password' => 'clave-segura-1',
            'password_confirmation' => 'clave-segura-1',
            'consent' => '1',
            ...$overrides,
        ];
    }
}
