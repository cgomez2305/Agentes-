<?php

namespace App\Livewire;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsApp\OutboundSender;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use RuntimeException;

#[Title('Bandeja')]
class Inbox extends Component
{
    use ScopedToTenant;

    public const FILTERS = [
        'atencion' => 'Necesitan atención',
        'todas' => 'Todas',
        'bot' => 'Con el bot',
        'persona' => 'Con una persona',
        'cerradas' => 'Cerradas',
    ];

    #[Url(as: 'filtro')]
    public string $filter = 'atencion';

    #[Url(as: 'c')]
    public ?int $selectedId = null;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public string $reply = '';

    /** @var array<int, string> Texto editado de cada borrador, por id de mensaje. */
    public array $draftEdits = [];

    public ?string $notice = null;

    public ?string $error = null;

    public function mount(): void
    {
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = 'atencion';
        }
    }

    public function updatedFilter(): void
    {
        unset($this->conversations);
    }

    public function select(int $id): void
    {
        $this->selectedId = Conversation::findOrFail($id)->id;
        $this->reset('reply', 'notice', 'error', 'draftEdits');
    }

    public function closePanel(): void
    {
        $this->selectedId = null;
    }

    public function take(): void
    {
        $this->selected()->handToHuman('Tomada por '.Auth::user()->name, Auth::user());
        $this->flash('Ahora atiendes tú esta conversación. El bot no responderá hasta que la devuelvas.');
    }

    public function returnToBot(): void
    {
        $this->selected()->returnToBot();
        $this->flash('El bot vuelve a responder esta conversación.');
    }

    public function close(): void
    {
        $this->selected()->update(['status' => Conversation::STATUS_CLOSED]);
        $this->flash('Conversación cerrada. Si el cliente escribe de nuevo, se abre una nueva.');
    }

    public function sendReply(OutboundSender $sender): void
    {
        $this->validate(['reply' => ['required', 'string', 'max:4000']], [
            'reply.required' => 'Escribe un mensaje antes de enviar.',
        ]);

        $this->attempt(function () use ($sender) {
            $message = $sender->replyAsHuman($this->selected(), Auth::user(), $this->reply);
            $this->reply = '';
            $this->reportDelivery($message, null);
        });
    }

    public function approveDraft(int $messageId, OutboundSender $sender): void
    {
        $draft = $this->selected()->messages()->where('status', 'draft')->findOrFail($messageId);

        $this->attempt(function () use ($draft, $sender) {
            $message = $sender->approveDraft($draft, Auth::user(), $this->draftEdits[$draft->id] ?? null);
            unset($this->draftEdits[$draft->id]);
            $this->reportDelivery($message, 'Borrador aprobado y enviado.');
        });
    }

    public function discardDraft(int $messageId): void
    {
        $draft = $this->selected()->messages()->where('status', 'draft')->findOrFail($messageId);
        $draft->update(['status' => 'discarded', 'user_id' => Auth::id()]);
        unset($this->draftEdits[$messageId]);
        $this->flash('Borrador descartado.');
    }

    /**
     * @return Collection<int, Conversation>
     */
    #[Computed]
    public function conversations(): Collection
    {
        return $this->filtered($this->baseQuery(), $this->filter)
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.trim($this->search).'%';
                $q->whereHas('contact', fn ($c) => $c->where('name', 'like', $term)->orWhere('wa_id', 'like', $term));
            })
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return collect(self::FILTERS)
            ->map(fn ($label, $key) => $this->filtered($this->baseQuery(), $key)->count())
            ->all();
    }

    #[Computed]
    public function current(): ?Conversation
    {
        if (! $this->selectedId) {
            return null;
        }

        return Conversation::with(['contact', 'assignee', 'messages' => fn ($q) => $q
            ->with('user')
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'discarded'))
            ->orderBy('id'),
        ])->find($this->selectedId);
    }

    public function render()
    {
        // Precarga el texto de cada borrador nuevo para poder editarlo.
        foreach ($this->current?->messages->where('status', 'draft') ?? [] as $draft) {
            $this->draftEdits[$draft->id] ??= $draft->content;
        }

        return view('livewire.inbox', [
            'filters' => self::FILTERS,
        ]);
    }

    /**
     * Subconsulta: el último mensaje enviado o recibido de cada conversación.
     */
    private function lastMessage(string $column): Builder
    {
        return Message::query()
            ->select($column)
            ->whereColumn('conversation_id', 'conversations.id')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', Message::UNSENT_STATUSES))
            ->latest('id')
            ->limit(1);
    }

    private function baseQuery(): Builder
    {
        return Conversation::query()
            ->whereHas('contact', fn ($q) => $q->where('is_test', false))
            ->with(['contact', 'assignee'])
            ->addSelect([
                'conversations.*',
                'last_preview' => $this->lastMessage('content'),
                'last_direction' => $this->lastMessage('direction'),
                'last_author' => $this->lastMessage('author'),
            ])
            ->withExists(['messages as has_draft' => fn ($q) => $q->where('status', 'draft')]);
    }

    private function filtered(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            // Una persona debe actuar: el cliente espera respuesta humana o hay un borrador por aprobar.
            'atencion' => $query->where(fn (Builder $q) => $q
                ->where(fn (Builder $q) => $q
                    ->where('status', Conversation::STATUS_HUMAN)
                    ->where($this->lastMessage('direction')->getQuery(), Message::IN))
                ->orWhereHas('messages', fn ($m) => $m->where('status', 'draft'))),
            'bot' => $query->where('status', Conversation::STATUS_BOT),
            'persona' => $query->where('status', Conversation::STATUS_HUMAN),
            'cerradas' => $query->where('status', Conversation::STATUS_CLOSED),
            default => $query->where('status', '!=', Conversation::STATUS_CLOSED),
        };
    }

    private function selected(): Conversation
    {
        return Conversation::findOrFail($this->selectedId);
    }

    private function attempt(callable $action): void
    {
        $this->error = null;

        try {
            $action();
        } catch (RuntimeException $e) {
            $this->error = $e->getMessage();
        }

        unset($this->current, $this->conversations, $this->counts);
    }

    private function reportDelivery(Message $message, ?string $success): void
    {
        if ($message->status !== 'failed') {
            $this->flash($success);

            return;
        }

        $this->flash(null);
        $this->error = match ($message->meta['error'] ?? null) {
            'sin_numero' => 'El mensaje quedó guardado, pero este negocio todavía no tiene un número de WhatsApp conectado.',
            default => 'WhatsApp no aceptó el mensaje. Revisa la conexión del número e intenta de nuevo.',
        };
    }

    private function flash(?string $message): void
    {
        $this->notice = $message;
        $this->error = null;
        unset($this->current, $this->conversations, $this->counts);
    }
}
