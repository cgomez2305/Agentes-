<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\CatalogItem;
use App\Models\KnowledgeSource;
use App\Services\Onboarding\CatalogImporter;
use App\Services\Onboarding\TenantProvisioner;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Title('Conocimiento y catálogo')]
class KnowledgeSettings extends Component
{
    use ScopedToTenant, WithFileUploads;

    public bool $showSourceForm = false;

    public string $sourceTitle = '';

    public string $sourceContent = '';

    /** @var array{name: string, price: string, duration: string, description: string} */
    public array $item = ['name' => '', 'price' => '', 'duration' => '', 'description' => ''];

    public ?int $editingItem = null;

    public $csv;

    public ?string $notice = null;

    public ?string $error = null;

    public function addSource(TenantProvisioner $provisioner): void
    {
        $this->validate([
            'sourceTitle' => ['required', 'string', 'max:120'],
            'sourceContent' => ['required', 'string', 'min:20', 'max:60000'],
        ], [
            'sourceTitle.required' => 'Ponle un título.',
            'sourceContent.required' => 'Pega el texto que el agente debe conocer.',
            'sourceContent.min' => 'El texto es muy corto para ser útil.',
        ]);

        $source = $provisioner->addKnowledge($this->tenant(), trim($this->sourceTitle), $this->sourceContent);
        $this->reset('sourceTitle', 'sourceContent', 'showSourceForm');
        $this->flash("\"{$source->title}\" agregado en {$source->chunks()->count()} fragmentos.");
    }

    public function deleteSource(int $id): void
    {
        KnowledgeSource::findOrFail($id)->delete();
        $this->flash('Fuente eliminada. El agente ya no la usa.');
    }

    public function editItem(int $id): void
    {
        $item = CatalogItem::findOrFail($id);
        $this->editingItem = $item->id;
        $this->item = [
            'name' => $item->name,
            'price' => $item->price === null ? '' : (string) $item->price,
            'duration' => $item->duration_minutes === null ? '' : (string) $item->duration_minutes,
            'description' => $item->description ?? '',
        ];
    }

    public function cancelEdit(): void
    {
        $this->reset('item', 'editingItem');
        $this->resetErrorBag();
    }

    public function saveItem(): void
    {
        $this->item['price'] = preg_replace('/\D/', '', $this->item['price']);
        $this->validate([
            'item.name' => ['required', 'string', 'max:160'],
            'item.price' => ['nullable', 'integer', 'min:0'],
            'item.duration' => ['nullable', 'integer', 'min:5', 'max:600'],
            'item.description' => ['nullable', 'string', 'max:500'],
        ], ['item.name.required' => 'Escribe el nombre del producto o servicio.']);

        $attributes = [
            'name' => trim($this->item['name']),
            'price' => $this->item['price'] === '' ? null : (int) $this->item['price'],
            'duration_minutes' => $this->item['duration'] === '' ? null : (int) $this->item['duration'],
            'description' => trim($this->item['description']) ?: null,
        ];

        $this->editingItem
            ? CatalogItem::findOrFail($this->editingItem)->update($attributes)
            : CatalogItem::create($attributes);

        $this->cancelEdit();
        $this->flash('Catálogo actualizado.');
    }

    public function toggleItem(int $id): void
    {
        $item = CatalogItem::findOrFail($id);
        $item->update(['is_available' => ! $item->is_available]);
        unset($this->items);
    }

    public function deleteItem(int $id): void
    {
        CatalogItem::findOrFail($id)->delete();
        $this->flash('Ítem eliminado del catálogo.');
    }

    public function importCsv(CatalogImporter $importer): void
    {
        $this->validate(['csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048']], [
            'csv.required' => 'Elige un archivo CSV.',
            'csv.mimes' => 'El archivo debe ser CSV.',
        ]);

        try {
            $count = $importer->import($this->csv->getRealPath());
            $this->reset('csv');
            $this->flash("Importados {$count} ítems al catálogo.");
        } catch (InvalidArgumentException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * @return Collection<int, KnowledgeSource>
     */
    #[Computed]
    public function sources(): Collection
    {
        return KnowledgeSource::withCount('chunks')->latest('id')->get();
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    #[Computed]
    public function items(): Collection
    {
        return CatalogItem::orderBy('name')->get();
    }

    public function render()
    {
        return view('livewire.settings.knowledge');
    }

    private function flash(string $message): void
    {
        $this->notice = $message;
        $this->error = null;
        unset($this->sources, $this->items);
    }
}
