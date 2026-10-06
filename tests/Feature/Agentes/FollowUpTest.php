<?php

namespace Tests\Feature\Agentes;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Followup;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\FollowUp\FollowUpService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class FollowUpTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-12 15:00', 'America/Bogota'));
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.'.uniqid()]]])]);
        $this->fakeLlm();
        $this->tenant = $this->makeTenant();
        app(TenantContext::class)->set($this->tenant);
    }

    public function test_nudges_a_silent_client_once_inside_the_window(): void
    {
        $this->llm->push('Laura, ¿pudiste revisar los horarios? Si quieres te separo el jueves a las 9.');
        $conversation = $this->conversationWithBotLast(hoursAgo: 5);

        $this->assertSame(['nudges' => 1, 'reminders' => 0], $this->runFollowUps());
        $this->assertSame(['nudges' => 0, 'reminders' => 0], $this->runFollowUps(), 'No se repite en el mismo silencio');

        $followup = $conversation->messages()->latest('id')->first();
        $this->assertSame('followup', $followup->source);
        $this->assertSame('sent', $followup->status);
        $this->assertStringContainsString('jueves a las 9', $followup->content);
        $this->assertStringContainsString('no responde hace 5 horas', collect($this->llm->requests[0]->messages)->last()['content']);
        Http::assertSent(fn ($r) => ($r['text']['body'] ?? '') === $followup->content);
    }

    public function test_does_not_nudge_when_it_is_too_soon_human_owned_window_closing_or_at_night(): void
    {
        $this->conversationWithBotLast(hoursAgo: 2);
        $this->conversationWithBotLast(hoursAgo: 5, status: Conversation::STATUS_HUMAN);
        $this->conversationWithBotLast(hoursAgo: 23.5);

        $this->assertSame(0, $this->runFollowUps()['nudges']);

        $this->travelTo(CarbonImmutable::parse('2026-10-12 21:30', 'America/Bogota'));
        $this->conversationWithBotLast(hoursAgo: 5);
        $this->assertSame(0, $this->runFollowUps()['nudges']);
        $this->assertCount(0, $this->llm->requests);
    }

    public function test_reminds_appointments_by_text_inside_the_window_and_by_template_outside(): void
    {
        $inside = $this->conversationWithBotLast(hoursAgo: 1, name: 'Laura Gómez');
        $this->appointment($inside->contact, '2026-10-13 09:00');

        $outside = $this->conversationWithBotLast(hoursAgo: 30, name: 'Pedro Ruiz');
        $this->appointment($outside->contact, '2026-10-13 10:00');

        $this->tenant->update(['followup_settings' => ['reminder_template' => 'recordatorio_cita', 'nudge_enabled' => false]]);

        $this->assertSame(['nudges' => 0, 'reminders' => 2], $this->runFollowUps());
        $this->assertSame(0, $this->runFollowUps()['reminders']);

        $text = $inside->messages()->latest('id')->first();
        $this->assertSame('reminder', $text->source);
        $this->assertStringContainsString('Hola Laura, te recordamos tu cita de *Valoración* el martes 13 de octubre a las 9:00 a. m. en Calle 10 # 43-20, Medellín', $text->content);

        Http::assertSent(fn ($r) => ($r['type'] ?? null) === 'template'
            && $r['template']['name'] === 'recordatorio_cita'
            && $r['template']['components'][0]['parameters'][0]['text'] === 'Pedro'
            && $r['template']['components'][0]['parameters'][2]['text'] === 'martes 13 de octubre a las 10:00 a. m.');
    }

    public function test_reminder_without_template_outside_the_window_is_skipped_and_recorded(): void
    {
        $outside = $this->conversationWithBotLast(hoursAgo: 30);
        $this->appointment($outside->contact, '2026-10-13 10:00');
        $this->tenant->update(['followup_settings' => ['nudge_enabled' => false]]);

        $this->assertSame(0, $this->runFollowUps()['reminders']);
        $this->assertSame('sin_plantilla', Followup::first()->reason);
        Http::assertNothingSent();
    }

    private function runFollowUps(): array
    {
        return app(FollowUpService::class)->run($this->tenant->fresh());
    }

    private function conversationWithBotLast(float $hoursAgo, string $status = Conversation::STATUS_BOT, string $name = 'Laura'): Conversation
    {
        $contact = Contact::create(['wa_id' => '57300'.random_int(1000000, 9999999), 'name' => $name]);
        $lastInbound = now()->subMinutes((int) (($hoursAgo + 0.1) * 60));
        $conversation = Conversation::create([
            'contact_id' => $contact->id,
            'channel_id' => $this->tenant->channels()->first()->id,
            'agent_id' => $this->tenant->agent->id,
            'status' => $status,
            'window_expires_at' => $lastInbound->copy()->addDay(),
        ]);

        foreach ([['in', 'Hola, ¿tienen cita el jueves?', null, $lastInbound], ['out', 'Tengo jueves 9:00 o 10:00. ¿Cuál prefieres?', 'llm', now()->subMinutes((int) ($hoursAgo * 60))]] as [$direction, $text, $source, $at]) {
            $message = new Message(['direction' => $direction, 'author' => $direction === 'in' ? 'contact' : 'bot', 'content' => $text, 'status' => $direction === 'in' ? 'received' : 'sent', 'source' => $source]);
            $message->conversation_id = $conversation->id;
            $message->created_at = $at;
            $message->save();
        }

        return $conversation->fresh();
    }

    private function appointment(Contact $contact, string $local): Appointment
    {
        $start = CarbonImmutable::parse($local, 'America/Bogota');

        return Appointment::create(['contact_id' => $contact->id, 'title' => 'Valoración', 'starts_at' => $start->utc(), 'ends_at' => $start->addMinutes(30)->utc()]);
    }
}
