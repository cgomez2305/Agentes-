<?php

namespace App\Livewire;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Contact;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Title('Contactos')]
class Contacts extends Component
{
    use ScopedToTenant, WithPagination;

    public const STAGES = [
        'nuevo' => 'Nuevo',
        'calificado' => 'Calificado',
        'cliente' => 'Cliente',
        'perdido' => 'Perdido',
    ];

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(as: 'etapa', except: '')]
    public string $stage = '';

    #[Url(as: 'etiqueta', except: '')]
    public string $tag = '';

    public ?int $openId = null;

    public string $newTag = '';

    public string $notes = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'stage', 'tag'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        $contact = $this->findContact($id);
        $this->openId = $contact->id;
        $this->notes = (string) (($contact->lead_data ?? [])['notas'] ?? '');
        $this->newTag = '';
    }

    public function close(): void
    {
        $this->openId = null;
    }

    public function setStage(int $id, string $stage): void
    {
        abort_unless(array_key_exists($stage, self::STAGES), 422);
        $this->findContact($id)->update(['stage' => $stage]);
        unset($this->contacts, $this->stageCounts, $this->current);
    }

    public function addTag(): void
    {
        $tag = mb_strtolower(trim($this->newTag));
        $this->validate(['newTag' => ['required', 'string', 'max:30']], ['newTag.required' => 'Escribe la etiqueta.']);

        $contact = $this->findContact($this->openId);
        $contact->update(['tags' => array_values(array_unique([...($contact->tags ?? []), $tag]))]);
        $this->newTag = '';
        unset($this->contacts, $this->allTags, $this->current);
    }

    public function removeTag(string $tag): void
    {
        $contact = $this->findContact($this->openId);
        $contact->update(['tags' => array_values(array_diff($contact->tags ?? [], [$tag]))]);
        unset($this->contacts, $this->allTags, $this->current);
    }

    public function saveNotes(): void
    {
        $this->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $contact = $this->findContact($this->openId);
        $data = $contact->lead_data ?? [];
        $data['notas'] = trim($this->notes);
        if ($data['notas'] === '') {
            unset($data['notas']);
        }
        $contact->update(['lead_data' => $data ?: null]);
        unset($this->current);
    }

    /**
     * Descarga los contactos del filtro actual en CSV (abre bien en Excel).
     */
    public function export(): StreamedResponse
    {
        $rows = $this->filtered()->orderBy('name')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}");
            fputcsv($out, ['nombre', 'whatsapp', 'etapa', 'etiquetas', 'datos', 'creado'], ';', escape: '');
            foreach ($rows as $contact) {
                fputcsv($out, [
                    $contact->name,
                    $contact->displayPhone(),
                    self::STAGES[$contact->stage] ?? $contact->stage,
                    implode(', ', $contact->tags ?? []),
                    collect($contact->lead_data ?? [])->map(fn ($v, $k) => "{$k}: {$v}")->implode(' | '),
                    $contact->created_at->format('Y-m-d'),
                ], ';', escape: '');
            }
            fclose($out);
        }, 'contactos-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    #[Computed]
    public function contacts(): LengthAwarePaginator
    {
        return $this->filtered()
            ->withCount(['conversations', 'appointments'])
            ->withMax('conversations', 'last_message_at')
            ->orderByDesc('updated_at')
            ->paginate(25);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function stageCounts(): array
    {
        $counts = Contact::query()->where('is_test', false)
            ->selectRaw('stage, count(*) as total')->groupBy('stage')->pluck('total', 'stage');

        return collect(self::STAGES)->map(fn ($label, $key) => (int) ($counts[$key] ?? 0))->all();
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function allTags(): array
    {
        return Contact::query()->where('is_test', false)->whereNotNull('tags')->pluck('tags')
            ->flatten()->unique()->sort()->values()->all();
    }

    #[Computed]
    public function current(): ?Contact
    {
        return $this->openId
            ? Contact::with([
                'conversations' => fn ($q) => $q->latest('id')->limit(5),
                'appointments' => fn ($q) => $q->latest('starts_at')->limit(5),
            ])->find($this->openId)
            : null;
    }

    public function render()
    {
        return view('livewire.contacts', ['stages' => self::STAGES]);
    }

    private function filtered(): Builder
    {
        return Contact::query()
            ->where('is_test', false)
            ->when($this->stage !== '', fn ($q) => $q->where('stage', $this->stage))
            ->when($this->tag !== '', fn ($q) => $q->whereJsonContains('tags', $this->tag))
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $digits = preg_replace('/\D/', '', $this->search);
                $q->where(fn ($q) => $q->where('name', 'like', $term)
                    ->when($digits !== '', fn ($q) => $q->orWhere('wa_id', 'like', '%'.$digits.'%')));
            });
    }

    private function findContact(?int $id): Contact
    {
        return Contact::where('is_test', false)->findOrFail($id);
    }
}
