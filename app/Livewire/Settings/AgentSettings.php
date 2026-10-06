<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Agent;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Agent\AgentRuntime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tu agente')]
class AgentSettings extends Component
{
    use ScopedToTenant;

    public string $name = '';

    public string $tone = '';

    public string $instructions = '';

    public string $mode = Agent::MODE_AUTO;

    public string $modelTier = 'fast';

    public string $handoffMessage = '';

    /** @var list<array{key: string, question: string}> */
    public array $fields = [];

    /** @var list<array{keywords: string, reply: string}> */
    public array $quickReplies = [];

    public string $testMessage = '';

    public ?string $notice = null;

    public ?string $testError = null;

    public function mount(): void
    {
        $agent = $this->agent();

        $this->name = $agent->name;
        $this->tone = $agent->tone;
        $this->instructions = $agent->instructions;
        $this->mode = $agent->mode;
        $this->modelTier = $agent->model_tier;
        $this->handoffMessage = $agent->handoff_message ?? '';
        $this->fields = array_values($agent->qualification_fields ?? []);
        $this->quickReplies = collect($agent->quick_replies ?? [])
            ->map(fn ($rule) => ['keywords' => implode(', ', $rule['keywords'] ?? []), 'reply' => $rule['reply'] ?? ''])
            ->values()
            ->all();
    }

    public function addField(): void
    {
        $this->fields[] = ['key' => '', 'question' => ''];
    }

    public function removeField(int $index): void
    {
        unset($this->fields[$index]);
        $this->fields = array_values($this->fields);
    }

    public function addQuickReply(): void
    {
        $this->quickReplies[] = ['keywords' => '', 'reply' => ''];
    }

    public function removeQuickReply(int $index): void
    {
        unset($this->quickReplies[$index]);
        $this->quickReplies = array_values($this->quickReplies);
    }

    public function save(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:60'],
            'tone' => ['required', 'string', 'max:120'],
            'instructions' => ['required', 'string', 'max:6000'],
            'mode' => ['required', 'in:'.Agent::MODE_AUTO.','.Agent::MODE_SUGGEST],
            'modelTier' => ['required', 'in:fast,smart'],
            'handoffMessage' => ['nullable', 'string', 'max:500'],
            'fields.*.question' => ['required', 'string', 'max:200'],
            'quickReplies.*.keywords' => ['required', 'string', 'max:300'],
            'quickReplies.*.reply' => ['required', 'string', 'max:700'],
        ], [
            'name.required' => 'Ponle un nombre al agente.',
            'instructions.required' => 'Escribe al menos una instrucción para el agente.',
            'fields.*.question.required' => 'Cada dato a obtener necesita su descripción.',
            'quickReplies.*.keywords.required' => 'Cada respuesta rápida necesita palabras clave.',
            'quickReplies.*.reply.required' => 'Cada respuesta rápida necesita su texto.',
        ]);

        $this->agent()->update([
            'name' => trim($this->name),
            'tone' => trim($this->tone),
            'instructions' => trim($this->instructions),
            'mode' => $this->mode,
            'model_tier' => $this->modelTier,
            'handoff_message' => trim($this->handoffMessage) ?: null,
            'qualification_fields' => collect($this->fields)
                ->map(fn ($f) => [
                    'key' => Str::of($f['key'] ?: $f['question'])->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(30, '')->toString(),
                    'question' => trim($f['question']),
                ])
                ->unique('key')
                ->values()
                ->all(),
            'quick_replies' => collect($this->quickReplies)
                ->map(fn ($r) => [
                    'keywords' => collect(explode(',', $r['keywords']))->map(fn ($k) => trim($k))->filter()->values()->all(),
                    'reply' => trim($r['reply']),
                ])
                ->values()
                ->all(),
        ]);

        $this->mount();
        $this->notice = 'Cambios guardados. El agente los usa desde el próximo mensaje.';
    }

    /**
     * Chat de prueba: usa el agente real (con su LLM y herramientas) sobre un
     * contacto marcado como prueba, sin pasar por WhatsApp.
     */
    public function sendTest(AgentRuntime $runtime): void
    {
        $text = trim($this->testMessage);
        if ($text === '') {
            return;
        }

        $conversation = $this->testConversation();
        $conversation->update(['status' => Conversation::STATUS_BOT, 'window_expires_at' => now()->addDay()]);
        $conversation->messages()->create(['direction' => Message::IN, 'author' => 'contact', 'content' => $text, 'status' => 'received']);
        $this->testMessage = '';
        $this->testError = null;

        try {
            $reply = $runtime->handle($conversation->fresh());
            $reply->message?->update(['status' => $reply->message->status === 'draft' ? 'draft' : 'sent']);
        } catch (\Throwable $e) {
            report($e);
            $this->testError = 'El agente no pudo responder. Revisa que la clave de la API de IA esté configurada en el servidor.';
        }

        unset($this->testMessages);
    }

    public function resetTest(): void
    {
        $contact = $this->testContact();
        $contact->conversations()->delete();
        $contact->appointments()->delete();
        $contact->update(['name' => null, 'lead_data' => null, 'stage' => 'nuevo']);
        $this->testError = null;
        unset($this->testMessages);
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function testMessages(): Collection
    {
        return $this->testConversation()->messages()->orderBy('id')->get();
    }

    public function render()
    {
        return view('livewire.settings.agent');
    }

    private function agent(): Agent
    {
        return Agent::where('is_active', true)->latest('id')->firstOrFail();
    }

    private function testContact(): Contact
    {
        return Contact::firstOrCreate(
            ['wa_id' => 'prueba-'.Auth::id()],
            ['is_test' => true, 'name' => null],
        );
    }

    private function testConversation(): Conversation
    {
        $contact = $this->testContact();

        return $contact->conversations()->latest('id')->first()
            ?? Conversation::create(['contact_id' => $contact->id, 'agent_id' => $this->agent()->id]);
    }
}
