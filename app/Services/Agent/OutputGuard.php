<?php

namespace App\Services\Agent;

use App\Models\CatalogItem;

/**
 * Valida y ajusta la respuesta del modelo antes de enviarla.
 */
class OutputGuard
{
    /**
     * @return array{ok: bool, text: string, issue: ?string}
     */
    public function check(string $text, string $evidence): array
    {
        $text = $this->toWhatsAppFormat(trim($text));

        if ($text === '') {
            return ['ok' => false, 'text' => '', 'issue' => 'respuesta_vacia'];
        }

        if ($price = $this->unverifiedPrice($text, $evidence)) {
            return ['ok' => false, 'text' => $text, 'issue' => "precio_no_verificado:{$price}"];
        }

        return ['ok' => true, 'text' => $this->limitLength($text), 'issue' => null];
    }

    /**
     * WhatsApp usa *negrita* y no interpreta encabezados ni enlaces Markdown.
     */
    private function toWhatsAppFormat(string $text): string
    {
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text);
        $text = preg_replace('/^#{1,6}\s*/m', '', $text);
        $text = preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)]+)\)/', '$1: $2', $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Devuelve el primer monto de la respuesta que no aparece ni en el
     * catálogo ni en lo que devolvieron las herramientas.
     */
    private function unverifiedPrice(string $text, string $evidence): ?string
    {
        if (! preg_match_all('/\$\s?(\d{1,3}(?:[.,]\d{3})+|\d{4,})/', $text, $matches)) {
            return null;
        }

        // Montos conocidos sin separadores de miles: "150.000" -> "150000".
        $known = preg_replace('/(?<=\d)[.,](?=\d{3})/', '', $evidence)
            .' '.CatalogItem::query()->whereNotNull('price')->pluck('price')->implode(' ');

        foreach ($matches[1] as $amount) {
            $digits = preg_replace('/\D/', '', $amount);

            if (! preg_match('/(?<!\d)'.$digits.'(?!\d)/', $known)) {
                return $amount;
            }
        }

        return null;
    }

    private function limitLength(string $text): string
    {
        $max = config('agentes.max_reply_chars');

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max);
        $lastStop = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, "\n") ?: 0, mb_strrpos($cut, '? ') ?: 0);

        return trim($lastStop > $max / 2 ? mb_substr($cut, 0, $lastStop + 1) : $cut.'…');
    }
}
