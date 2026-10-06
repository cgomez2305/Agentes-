<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Inbox;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.HUMANO']]])]);
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Carolina Gómez']);
    }

    public function test_guests_are_sent_to_the_login(): void
    {
        $this->get('/bandeja')->assertRedirect('/ingresar');
    }

    public function test_users_log_in_and_see_their_inbox(): void
    {
        $this->post('/ingresar', ['email' => $this->user->email, 'password' => 'password'])
            ->assertRedirect('/bandeja');

        $this->get('/bandeja')->assertOk()->assertSee('Bandeja')->assertSee('Clínica Dental Sonrisa');
    }

    public function test_wrong_password_shows_an_error(): void
    {
        $this->post('/ingresar', ['email' => $this->user->email, 'password' => 'otra'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_attention_filter_lists_only_conversations_waiting_for_a_person(): void
    {
        $waiting = $this->conversation('Julián', Conversation::STATUS_HUMAN, ['in' => 'Sigo esperando']);
        $answered = $this->conversation('Mariana', Conversation::STATUS_HUMAN, ['in' => 'Hola', 'out' => 'Hola Mariana']);
        $withBot = $this->conversation('Laura', Conversation::STATUS_BOT, ['in' => 'Hola']);
        $withDraft = $this->conversation('Pedro', Conversation::STATUS_BOT, ['in' => '¿Atienden con Sura?']);
        $withDraft->messages()->create(['direction' => Message::OUT, 'author' => 'bot', 'content' => 'Sí', 'status' => 'draft', 'source' => 'llm']);

        $component = Livewire::actingAs($this->user)->test(Inbox::class);

        $ids = $component->instance()->conversations->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$waiting->id, $withDraft->id], $ids);
        $this->assertSame(2, $component->instance()->counts['atencion']);
        $this->assertSame(4, $component->instance()->counts['todas']);
        $this->assertNotContains($answered->id, $ids);
        $this->assertNotContains($withBot->id, $ids);
    }

    public function test_a_person_replies_and_takes_the_conversation(): void
    {
        $conversation = $this->conversation('Laura', Conversation::STATUS_BOT, ['in' => '¿Tienen cita mañana?']);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $conversation->id)
            ->set('reply', 'Hola Laura, soy Carolina. Mañana a las 10 tenemos espacio.')
            ->call('sendReply')
            ->assertSet('reply', '')
            ->assertHasNoErrors();

        $conversation->refresh();
        $this->assertSame(Conversation::STATUS_HUMAN, $conversation->status);
        $this->assertSame($this->user->id, $conversation->assigned_user_id);

        $message = $conversation->messages()->latest('id')->first();
        $this->assertSame('human', $message->author);
        $this->assertSame('sent', $message->status);
        $this->assertSame($this->user->id, $message->user_id);

        Http::assertSent(fn ($request) => ($request['text']['body'] ?? null) === 'Hola Laura, soy Carolina. Mañana a las 10 tenemos espacio.');
    }

    public function test_replies_are_blocked_when_the_24_hour_window_is_closed(): void
    {
        $conversation = $this->conversation('Laura', Conversation::STATUS_HUMAN, ['in' => 'Hola']);
        $conversation->update(['window_expires_at' => now()->subHour()]);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $conversation->id)
            ->set('reply', '¿Sigues interesada?')
            ->call('sendReply')
            ->assertSet('error', fn ($error) => str_contains($error, '24 horas'));

        Http::assertNothingSent();
    }

    public function test_drafts_can_be_edited_approved_and_discarded(): void
    {
        $conversation = $this->conversation('Pedro', Conversation::STATUS_BOT, ['in' => '¿Atienden con Sura?']);
        $draft = $conversation->messages()->create(['direction' => Message::OUT, 'author' => 'bot', 'content' => 'Sí, con Sura.', 'status' => 'draft', 'source' => 'llm']);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $conversation->id)
            ->assertSet("draftEdits.{$draft->id}", 'Sí, con Sura.')
            ->set("draftEdits.{$draft->id}", 'Sí, con Sura prepagada y orden previa.')
            ->call('approveDraft', $draft->id);

        $draft->refresh();
        $this->assertSame('sent', $draft->status);
        $this->assertSame('Sí, con Sura prepagada y orden previa.', $draft->content);
        $this->assertTrue($draft->meta['edited']);

        $second = $conversation->messages()->create(['direction' => Message::OUT, 'author' => 'bot', 'content' => 'Otro borrador', 'status' => 'draft', 'source' => 'llm']);
        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $conversation->id)
            ->call('discardDraft', $second->id);

        $this->assertSame('discarded', $second->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_approving_without_a_connected_number_reports_it_instead_of_success(): void
    {
        $conversation = $this->conversation('Pedro', Conversation::STATUS_BOT, ['in' => '¿Atienden con Sura?']);
        $conversation->update(['channel_id' => null]);
        $draft = $conversation->messages()->create(['direction' => Message::OUT, 'author' => 'bot', 'content' => 'Sí.', 'status' => 'draft', 'source' => 'llm']);

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $conversation->id)
            ->call('approveDraft', $draft->id)
            ->assertSet('notice', null)
            ->assertSet('error', fn ($error) => str_contains($error, 'no tiene un número de WhatsApp conectado'));

        $this->assertSame('failed', $draft->fresh()->status);
    }

    public function test_take_and_return_to_bot(): void
    {
        $conversation = $this->conversation('Laura', Conversation::STATUS_BOT, ['in' => 'Hola']);

        $component = Livewire::actingAs($this->user)->test(Inbox::class)->call('select', $conversation->id)->call('take');
        $this->assertSame(Conversation::STATUS_HUMAN, $conversation->fresh()->status);

        $component->call('returnToBot');
        $this->assertSame(Conversation::STATUS_BOT, $conversation->fresh()->status);
        $this->assertNull($conversation->fresh()->assigned_user_id);
    }

    public function test_users_cannot_open_another_businesses_conversation(): void
    {
        $other = $this->makeTenant('Inmobiliaria Horizonte', '444555666');
        $foreign = app(TenantContext::class)->run($other, fn () => $this->conversation('Ajeno', Conversation::STATUS_BOT, ['in' => 'Hola']));

        Livewire::actingAs($this->user)->test(Inbox::class)
            ->call('select', $foreign->id)
            ->assertStatus(404);

        $this->assertNotContains($foreign->id, Livewire::actingAs($this->user)->test(Inbox::class)->set('filter', 'todas')->instance()->conversations->pluck('id')->all());
    }

    /**
     * @param  array<string, string>  $messages  dirección => texto, en orden
     */
    private function conversation(string $name, string $status, array $messages): Conversation
    {
        $tenant = app(TenantContext::class)->get() ?? $this->tenant;

        return app(TenantContext::class)->run($tenant, function () use ($name, $status, $messages, $tenant) {
            $contact = Contact::create(['wa_id' => '57300'.random_int(1000000, 9999999), 'name' => $name]);
            $conversation = Conversation::create([
                'contact_id' => $contact->id,
                'channel_id' => $tenant->channels()->first()->id,
                'agent_id' => Agent::first()->id,
                'status' => $status,
                'window_expires_at' => now()->addDay(),
            ]);

            foreach ($messages as $direction => $text) {
                $conversation->messages()->create(['direction' => $direction, 'content' => $text, 'status' => $direction === 'in' ? 'received' : 'sent']);
            }

            return $conversation;
        });
    }
}
