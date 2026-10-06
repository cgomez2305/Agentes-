<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Inbox;
use App\Livewire\Settings\AgentSettings;
use App\Livewire\Settings\BusinessSettings;
use App\Livewire\Settings\FollowupSettings;
use App\Livewire\Settings\KnowledgeSettings;
use App\Models\Agent;
use App\Models\CatalogItem;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_every_settings_page_renders(): void
    {
        foreach (['/configuracion', '/configuracion/negocio', '/configuracion/conocimiento', '/configuracion/seguimientos'] as $path) {
            $this->actingAs($this->user)->get($path)->assertOk()->assertSee('Configuración');
        }
    }

    public function test_agent_settings_are_saved_with_clean_keys_and_keywords(): void
    {
        Livewire::actingAs($this->user)->test(AgentSettings::class)
            ->set('name', 'Mateo')
            ->set('mode', Agent::MODE_SUGGEST)
            ->set('fields', [['key' => '', 'question' => 'Presupuesto aproximado'], ['key' => 'ciudad', 'question' => 'Ciudad']])
            ->set('quickReplies', [['keywords' => ' parqueadero ,  parqueo ', 'reply' => 'Sí, en el sótano.']])
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('notice', fn ($n) => str_contains($n, 'guardados'));

        $agent = $this->agent();
        $this->assertSame('Mateo', $agent->name);
        $this->assertSame(Agent::MODE_SUGGEST, $agent->mode);
        $this->assertSame(['presupuesto_aproximado', 'ciudad'], array_column($agent->qualification_fields, 'key'));
        $this->assertSame(['parqueadero', 'parqueo'], $agent->quick_replies[0]['keywords']);
    }

    public function test_incomplete_quick_replies_are_rejected(): void
    {
        Livewire::actingAs($this->user)->test(AgentSettings::class)
            ->set('quickReplies', [['keywords' => 'horario', 'reply' => '']])
            ->call('save')
            ->assertHasErrors(['quickReplies.0.reply']);
    }

    public function test_the_test_chat_uses_the_real_agent_and_stays_out_of_the_inbox(): void
    {
        $this->llm->push('¡Hola! La limpieza cuesta $150.000 COP.', [['name' => 'consultar_catalogo', 'input' => ['consulta' => 'limpieza']]]);

        Livewire::actingAs($this->user)->test(AgentSettings::class)
            ->set('testMessage', '¿Cuánto vale la limpieza?')
            ->call('sendTest')
            ->assertSet('testMessage', '')
            ->assertSee('La limpieza cuesta $150.000 COP.');

        $this->assertCount(1, $this->llm->requests);
        $this->assertSame(0, Livewire::actingAs($this->user)->test(Inbox::class)->set('filter', 'todas')->instance()->conversations->count());

        Livewire::actingAs($this->user)->test(AgentSettings::class)->call('resetTest')->assertSee('Prueba con:');
    }

    public function test_business_hours_and_profile(): void
    {
        Livewire::actingAs($this->user)->test(BusinessSettings::class)
            ->set('profile.direccion', 'Carrera 43A # 1-50, Medellín')
            ->set('hours.mon', ['open' => true, 'from' => '07:00', 'to' => '19:00'])
            ->set('hours.sun', ['open' => false, 'from' => '08:00', 'to' => '18:00'])
            ->call('save')
            ->assertHasNoErrors();

        $tenant = $this->tenant->fresh();
        $this->assertSame(['07:00', '19:00'], $tenant->business_hours['mon']);
        $this->assertArrayNotHasKey('sun', $tenant->business_hours);
        $this->assertSame('Carrera 43A # 1-50, Medellín', $tenant->profile['direccion']);

        Livewire::actingAs($this->user)->test(BusinessSettings::class)
            ->set('hours.tue', ['open' => true, 'from' => '18:00', 'to' => '08:00'])
            ->call('save')
            ->assertHasErrors(['hours.tue.to']);
    }

    public function test_knowledge_and_catalog_management(): void
    {
        $component = Livewire::actingAs($this->user)->test(KnowledgeSettings::class)
            ->set('sourceTitle', 'Parqueadero')
            ->set('sourceContent', 'Hay parqueadero gratuito para pacientes en el sótano del edificio.')
            ->call('addSource')
            ->assertHasNoErrors()
            ->set('item', ['name' => 'Diseño de sonrisa', 'price' => '$ 2.500.000', 'duration' => '90', 'description' => ''])
            ->call('saveItem')
            ->assertHasNoErrors();

        $item = $this->inTenant(fn () => CatalogItem::where('name', 'Diseño de sonrisa')->first());
        $this->assertSame(2500000, $item->price);
        $this->assertSame(90, $item->duration_minutes);

        $component->call('toggleItem', $item->id);
        $this->assertFalse($item->fresh()->is_available);

        $source = $this->inTenant(fn () => KnowledgeSource::first());
        $component->call('deleteSource', $source->id);
        $this->assertNull($source->fresh());
    }

    public function test_catalog_csv_import_accepts_excel_semicolons(): void
    {
        $csv = UploadedFile::fake()->createWithContent('catalogo.csv', "\u{FEFF}nombre;precio;duracion_min\nCorona en porcelana;1.200.000;60\nCarillas;900000;\n");

        Livewire::actingAs($this->user)->test(KnowledgeSettings::class)
            ->set('csv', $csv)
            ->call('importCsv')
            ->assertSet('notice', 'Importados 2 ítems al catálogo.');

        $this->assertSame(1200000, $this->inTenant(fn () => CatalogItem::where('name', 'Corona en porcelana')->value('price')));
    }

    public function test_followup_settings_validate_the_template_name(): void
    {
        Livewire::actingAs($this->user)->test(FollowupSettings::class)
            ->set('settings.reminder_template', 'Recordatorio Cita')
            ->call('save')
            ->assertHasErrors(['settings.reminder_template'])
            ->set('settings.reminder_template', 'recordatorio_cita')
            ->set('settings.nudge_after_hours', 6)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(6, $this->tenant->fresh()->followup('nudge_after_hours'));
    }

    public function test_cannot_touch_another_businesses_catalog(): void
    {
        $other = $this->makeTenant('Inmobiliaria Horizonte', '444555666');
        $foreign = app(TenantContext::class)->run($other, fn () => CatalogItem::first());

        Livewire::actingAs($this->user)->test(KnowledgeSettings::class)
            ->call('deleteItem', $foreign->id)
            ->assertStatus(404);

        $this->assertNotNull($foreign->fresh());
    }

    private function agent(): Agent
    {
        return $this->inTenant(fn () => Agent::first());
    }

    private function inTenant(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->tenant, $callback);
    }
}
