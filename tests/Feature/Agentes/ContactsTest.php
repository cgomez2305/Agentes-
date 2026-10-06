<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Contacts;
use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class ContactsTest extends TestCase
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

        app(TenantContext::class)->run($this->tenant, function () {
            Contact::create(['wa_id' => '573001112233', 'name' => 'Laura Restrepo', 'stage' => 'calificado', 'tags' => ['ortodoncia'], 'lead_data' => ['tratamiento' => 'limpieza']]);
            Contact::create(['wa_id' => '573104445566', 'name' => 'Julián Ortiz', 'stage' => 'nuevo']);
            Contact::create(['wa_id' => 'prueba-1', 'is_test' => true]);
        });
    }

    public function test_lists_filters_and_counts_without_test_contacts(): void
    {
        $this->actingAs($this->user)->get('/contactos')->assertOk()->assertSee('Laura Restrepo')->assertSee('Tratamiento: limpieza');

        $component = Livewire::actingAs($this->user)->test(Contacts::class);
        $this->assertSame(2, $component->instance()->contacts->total());
        $this->assertSame(['nuevo' => 1, 'calificado' => 1, 'cliente' => 0, 'perdido' => 0], $component->instance()->stageCounts);

        $this->assertSame(['Laura Restrepo'], $component->set('stage', 'calificado')->instance()->contacts->pluck('name')->all());
        $this->assertSame(['Julián Ortiz'], $component->set('stage', '')->set('search', '310 444')->instance()->contacts->pluck('name')->all());
        $this->assertSame(['Laura Restrepo'], $component->set('search', '')->set('tag', 'ortodoncia')->instance()->contacts->pluck('name')->all());
    }

    public function test_stage_tags_and_notes_are_editable(): void
    {
        $laura = $this->contact('Laura Restrepo');

        Livewire::actingAs($this->user)->test(Contacts::class)
            ->call('open', $laura->id)
            ->call('setStage', $laura->id, 'cliente')
            ->set('newTag', ' VIP ')
            ->call('addTag')
            ->call('removeTag', 'ortodoncia')
            ->set('notes', 'Prefiere que la llamen en la tarde.')
            ->call('saveNotes');

        $laura->refresh();
        $this->assertSame('cliente', $laura->stage);
        $this->assertSame(['vip'], $laura->tags);
        $this->assertSame('Prefiere que la llamen en la tarde.', $laura->lead_data['notas']);
        $this->assertSame('limpieza', $laura->lead_data['tratamiento']);
    }

    public function test_exports_the_filtered_contacts_as_csv(): void
    {
        $response = Livewire::actingAs($this->user)->test(Contacts::class)->set('stage', 'calificado')->call('export');

        $response->assertFileDownloaded('contactos-'.now()->format('Y-m-d').'.csv');
    }

    public function test_other_businesses_and_test_contacts_are_not_reachable(): void
    {
        $other = $this->makeTenant('Inmobiliaria Horizonte', '444555666');
        $foreign = app(TenantContext::class)->run($other, fn () => Contact::create(['wa_id' => '573209990011', 'name' => 'Ajeno']));
        $test = $this->contact(null, 'prueba-1');

        Livewire::actingAs($this->user)->test(Contacts::class)->call('setStage', $foreign->id, 'perdido')->assertStatus(404);
        Livewire::actingAs($this->user)->test(Contacts::class)->call('open', $test->id)->assertStatus(404);

        $this->assertSame('nuevo', $foreign->fresh()->stage);
    }

    private function contact(?string $name, ?string $waId = null): Contact
    {
        return app(TenantContext::class)->run($this->tenant, fn () => $waId
            ? Contact::where('wa_id', $waId)->first()
            : Contact::where('name', $name)->first());
    }
}
