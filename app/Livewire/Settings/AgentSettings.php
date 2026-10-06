<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Agent;
use App\Models\CatalogItem;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\KnowledgeSource;
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

    /** Mensajes sugeridos para el chat de prueba, según el tipo de negocio. */
    public const TEST_PROMPTS = [
        'clinica' => ['¿Cuánto vale una limpieza?', 'Quiero agendar para el jueves'],
        'inmobiliaria' => ['¿Tienen apartamentos para invertir?', 'Quiero visitar la sala de ventas el sábado'],
        'tienda' => ['¿Hacen envíos a Cali?', '¿Qué me recomiendas para regalar?'],
    ];

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

    /**
     * Guía de primeros pasos: se oculta cuando todo está listo.
     *
     * @return list<array{label: string, hint: string, done: bool, route: ?string}>
     */
    #[Computed]
    public function onboarding(): array
    {
        $tenant = $this->tenant()->fresh();
        $agent = $this->agent();

        return [
            ['label' => 'Revisa las instrucciones del agente', 'hint' => 'Ajusta el tono y lo que debe lograr en esta página.', 'done' => $agent->updated_at->gt($agent->created_at->addSecond()), 'route' => null],
            ['label' => 'Completa dirección y horario', 'hint' => 'El agente los usa para responder y para agendar.', 'done' => filled(($tenant->profile ?? [])['direccion'] ?? null) && filled($tenant->business_hours), 'route' => 'settings.business'],
            ['label' => 'Agrega tu catálogo con precios', 'hint' => 'Sin catálogo, el agente no da precios.', 'done' => CatalogItem::exists(), 'route' => 'settings.knowledge'],
            ['label' => 'Carga información del negocio', 'hint' => 'Preguntas frecuentes, políticas, formas de pago.', 'done' => KnowledgeSource::exists(), 'route' => 'settings.knowledge'],
            ['label' => 'Prueba el agente', 'hint' => 'Usa el chat de la derecha como si fueras un cliente.', 'done' => $this->testMessages->isNotEmpty(), 'route' => null],
            ['label' => 'Conecta tu WhatsApp', 'hint' => 'Mientras se habilita la conexión automática, nuestro equipo conecta tu número con la API oficial de Meta.', 'done' => Channel::exists(), 'route' => null],
        ];
    }

    public function render()
    {
        return view('livewire.settings.agent', [
            'welcome' => session('welcome', false),
            'prompts' => self::TEST_PROMPTS[$this->tenant()->vertical] ?? self::TEST_PROMPTS['clinica'],
        ]);
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
