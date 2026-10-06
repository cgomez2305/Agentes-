<?php

namespace App\Support;

use Illuminate\Support\Str;

class Text
{
    /**
     * Minúsculas, sin tildes y sin signos: base de la búsqueda por palabras.
     */
    public static function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Palabras significativas (sin conectores) de un texto.
     *
     * @return list<string>
     */
    public static function keywords(string $text): array
    {
        $words = explode(' ', self::normalize($text));

        return array_values(array_unique(array_filter(
            $words,
            fn (string $word) => strlen($word) > 2 && ! in_array($word, self::STOPWORDS, true),
        )));
    }

    private const STOPWORDS = [
        'que', 'para', 'por', 'con', 'una', 'uno', 'los', 'las', 'del', 'como', 'mas', 'pero',
        'sus', 'esta', 'este', 'esto', 'son', 'hay', 'tiene', 'tienen', 'quiero', 'quisiera',
        'saber', 'favor', 'hola', 'buenas', 'buenos', 'dias', 'tardes', 'noches', 'gracias',
        'cual', 'cuales', 'donde', 'cuando', 'cuanto', 'puedo', 'pueden', 'me', 'les', 'nos',
        'muy', 'todo', 'todos', 'algo', 'ustedes', 'usted', 'tengo', 'seria', 'sobre',
    ];
}
