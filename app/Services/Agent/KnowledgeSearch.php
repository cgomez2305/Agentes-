<?php

namespace App\Services\Agent;

use App\Models\CatalogItem;
use App\Models\KnowledgeChunk;
use App\Support\Text;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Recuperación por palabras clave sobre la base de conocimiento y el catálogo.
 * Es la primera versión del RAG: suficiente para el piloto y sin costo. La
 * búsqueda vectorial (pgvector) se suma después detrás de esta misma interfaz.
 */
class KnowledgeSearch
{
    /**
     * @return Collection<int, KnowledgeChunk>
     */
    public function chunks(string $query, int $limit): Collection
    {
        return $this->rank(KnowledgeChunk::query(), $query, $limit);
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    public function catalog(string $query, int $limit): Collection
    {
        return $this->rank(CatalogItem::query()->where('is_available', true), $query, $limit);
    }

    private function rank(Builder $query, string $text, int $limit): Collection
    {
        $keywords = Text::keywords($text);

        if ($keywords === []) {
            return new Collection;
        }

        $candidates = $query
            ->where(function (Builder $q) use ($keywords) {
                foreach ($keywords as $word) {
                    $q->orWhere('search_text', 'like', '%'.$this->stem($word).'%');
                }
            })
            ->limit(200)
            ->get();

        return $candidates
            ->map(function ($row) use ($keywords) {
                $haystack = ' '.$row->search_text.' ';
                $score = 0;
                foreach ($keywords as $word) {
                    if (str_contains($haystack, ' '.$word.' ')) {
                        $score += 2;
                    } elseif (str_contains($haystack, $this->stem($word))) {
                        $score += 1;
                    }
                }
                $row->setAttribute('score', $score);

                return $row;
            })
            ->filter(fn ($row) => $row->score > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    /**
     * Raíz aproximada para tolerar plurales y variaciones ("limpiezas" ~ "limpieza").
     */
    private function stem(string $word): string
    {
        return strlen($word) > 5 ? substr($word, 0, strlen($word) - 2) : $word;
    }
}
