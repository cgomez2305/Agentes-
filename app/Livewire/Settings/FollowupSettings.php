<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\ScopedToTenant;
use App\Models\Followup;
use App\Models\Tenant;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Seguimientos')]
class FollowupSettings extends Component
{
    use ScopedToTenant;

    /** @var array<string, mixed> */
    public array $settings = [];

    public ?string $notice = null;

    public function mount(): void
    {
        $tenant = $this->tenant()->fresh();
        $this->settings = collect(Tenant::FOLLOWUP_DEFAULTS)->map(fn ($v, $key) => $tenant->followup($key))->all();
        $this->settings['reminder_template'] ??= '';
    }

    public function save(): void
    {
        $this->validate([
            'settings.nudge_enabled' => ['boolean'],
            'settings.nudge_after_hours' => ['required', 'integer', 'min:1', 'max:20'],
            'settings.reminder_enabled' => ['boolean'],
            'settings.reminder_hours_before' => ['required', 'integer', 'min:2', 'max:72'],
            'settings.reminder_template' => ['nullable', 'regex:/^[a-z0-9_]+$/', 'max:100'],
            'settings.template_language' => ['required', 'in:es,es_ES,es_MX,es_AR,en_US'],
        ], ['settings.reminder_template.regex' => 'El nombre de la plantilla va en minúsculas, sin espacios (como en Meta).']);

        $this->tenant()->update(['followup_settings' => [
            'nudge_enabled' => (bool) $this->settings['nudge_enabled'],
            'nudge_after_hours' => (int) $this->settings['nudge_after_hours'],
            'reminder_enabled' => (bool) $this->settings['reminder_enabled'],
            'reminder_hours_before' => (int) $this->settings['reminder_hours_before'],
            'reminder_template' => $this->settings['reminder_template'] ?: null,
            'template_language' => $this->settings['template_language'],
        ]]);

        $this->notice = 'Seguimientos guardados.';
    }

    /**
     * @return Collection<int, Followup>
     */
    #[Computed]
    public function recent(): Collection
    {
        return Followup::with(['message', 'conversation.contact'])->latest('id')->limit(8)->get();
    }

    public function render()
    {
        return view('livewire.settings.followups');
    }
}
