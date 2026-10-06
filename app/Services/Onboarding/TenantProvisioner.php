<?php

namespace App\Services\Onboarding;

use App\Models\Agent;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Crea un negocio con su agente a partir de una plantilla por vertical.
 */
class TenantProvisioner
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @return list<string>
     */
    public static function verticals(): array
    {
        return array_map(fn ($file) => basename($file, '.php'), glob(resource_path('verticals/*.php')));
    }

    /**
     * @param  array<string, string>  $profile  Datos fijos: direccion, telefono, web...
     */
    public function create(string $name, string $vertical, array $profile = []): Tenant
    {
        $path = resource_path("verticals/{$vertical}.php");

        if (! is_file($path)) {
            throw new InvalidArgumentException("No existe la plantilla de vertical '{$vertical}'.");
        }

        $template = require $path;

        return DB::transaction(function () use ($name, $vertical, $profile, $template) {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'vertical' => $vertical,
                'business_hours' => $template['business_hours'] ?? null,
                'profile' => $profile,
            ]);

            $this->tenants->run($tenant, function () use ($tenant, $template) {
                $agent = $template['agent'];
                $agent['quick_replies'] = $this->fillPlaceholders($tenant, $agent['quick_replies'] ?? []);

                Agent::create($agent);
            });

            return $tenant;
        });
    }

    /**
     * Divide un texto en fragmentos por párrafos y los guarda como conocimiento.
     */
    public function addKnowledge(Tenant $tenant, string $title, string $content, string $type = 'text', ?string $origin = null): KnowledgeSource
    {
        return $this->tenants->run($tenant, function () use ($title, $content, $type, $origin) {
            $source = KnowledgeSource::create(['type' => $type, 'title' => $title, 'origin' => $origin]);

            foreach ($this->chunk($content) as $position => $text) {
                KnowledgeChunk::create([
                    'knowledge_source_id' => $source->id,
                    'content' => $text,
                    'position' => $position,
                ]);
            }

            return $source;
        });
    }

    /**
     * @return list<string>
     */
    public function chunk(string $content, int $maxChars = 400): array
    {
        $paragraphs = preg_split("/\n\s*\n/", str_replace("\r\n", "\n", trim($content)));
        $chunks = [];
        $current = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if ($current !== '' && mb_strlen($current) + mb_strlen($paragraph) + 2 > $maxChars) {
                $chunks[] = $current;
                $current = '';
            }

            // Un párrafo más largo que el máximo se corta por frases.
            while (mb_strlen($paragraph) > $maxChars) {
                $cut = mb_strrpos(mb_substr($paragraph, 0, $maxChars), '. ') ?: $maxChars;
                $chunks[] = trim(mb_substr($paragraph, 0, $cut + 1));
                $paragraph = trim(mb_substr($paragraph, $cut + 1));
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function fillPlaceholders(Tenant $tenant, array $rules): array
    {
        $values = ['{horario}' => rtrim($tenant->describeBusinessHours(), '.')];
        foreach ($tenant->profile ?? [] as $key => $value) {
            $values['{'.$key.'}'] = $value;
        }

        // Una respuesta rápida con datos faltantes no sirve: se descarta.
        return array_values(array_filter(
            array_map(fn ($rule) => [...$rule, 'reply' => strtr($rule['reply'], $values)], $rules),
            fn ($rule) => ! preg_match('/\{[a-z_]+\}/', $rule['reply']),
        ));
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 2;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
