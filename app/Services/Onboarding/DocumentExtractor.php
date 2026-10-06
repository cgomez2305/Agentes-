<?php

namespace App\Services\Onboarding;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Smalot\PdfParser\Parser;

/**
 * Convierte PDF y páginas web en texto plano para la base de conocimiento.
 */
class DocumentExtractor
{
    private const MAX_BYTES = 3_000_000;

    private const MAX_REDIRECTS = 3;

    /** @var callable(string): list<string> Resuelve un dominio a IPs (reemplazable en pruebas). */
    public $resolver;

    public function __construct()
    {
        $this->resolver = fn (string $host) => gethostbynamel($host) ?: [];
    }

    public function fromPdf(string $path): string
    {
        try {
            $text = (new Parser)->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new InvalidArgumentException('No se pudo leer el PDF. Si es una imagen escaneada, copia el texto y pégalo.', previous: $e);
        }

        $text = $this->clean($text);

        if (mb_strlen($text) < 20) {
            throw new InvalidArgumentException('El PDF no tiene texto seleccionable. Si es una imagen escaneada, copia el texto y pégalo.');
        }

        return $text;
    }

    /**
     * @return array{title: string, text: string}
     */
    public function fromUrl(string $url): array
    {
        $html = $this->fetch($url);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $title = trim((string) $xpath->evaluate('string(//title)')) ?: (parse_url($url, PHP_URL_HOST) ?? $url);

        // Menús, pies, formularios y scripts no aportan conocimiento.
        foreach (['script', 'style', 'noscript', 'nav', 'footer', 'header', 'form', 'svg', 'iframe', 'aside'] as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $xpath->query('//main')->item(0) ?? $xpath->query('//article')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
        $inner = $root ? $dom->saveHTML($root) : '';

        // Cada bloque se vuelve un párrafo para que el troceo respete los temas.
        $inner = preg_replace('#</?(p|div|section|h[1-6]|li|tr|br|ul|ol|table|blockquote)[^>]*>#i', "\n\n", $inner);
        $text = $this->clean(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (mb_strlen($text) < 40) {
            throw new InvalidArgumentException('La página casi no tiene texto. Puede que se genere con JavaScript; copia el texto y pégalo.');
        }

        return ['title' => mb_substr($title, 0, 120), 'text' => $text];
    }

    private function fetch(string $url): string
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            [$host, $ip, $port] = $this->assertPublic($url);

            $response = Http::withOptions([
                'allow_redirects' => false,
                // Se fija la IP ya validada para que el DNS no pueda cambiarla entre la validación y la petición.
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
            ])
                ->withHeaders(['User-Agent' => 'AgentesBot/1.0 (+lectura de base de conocimiento)', 'Accept' => 'text/html'])
                ->timeout(10)
                ->get($url);

            if ($response->redirect()) {
                $location = $response->header('Location');
                $url = str_starts_with($location, 'http') ? $location : rtrim(preg_replace('#^(https?://[^/]+).*$#', '$1', $url), '/').'/'.ltrim($location, '/');

                continue;
            }

            if ($response->failed()) {
                throw new InvalidArgumentException("La página respondió con error {$response->status()}.");
            }

            if (! str_contains(strtolower($response->header('Content-Type')), 'html')) {
                throw new InvalidArgumentException('La dirección no es una página web (HTML).');
            }

            $body = $response->body();
            if (strlen($body) > self::MAX_BYTES) {
                throw new InvalidArgumentException('La página es demasiado grande.');
            }

            return $body;
        }

        throw new InvalidArgumentException('La página redirige demasiadas veces.');
    }

    /**
     * Solo direcciones http(s) públicas: bloquea la red interna del servidor (SSRF).
     *
     * @return array{0: string, 1: string, 2: int}
     */
    private function assertPublic(string $url): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Escribe una dirección completa, por ejemplo https://tunegocio.com/preguntas.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver)($host);

        if ($ips === []) {
            throw new InvalidArgumentException('No encontramos esa página. Revisa la dirección.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new InvalidArgumentException('Esa dirección no es pública.');
            }
        }

        return [$host, $ips[0], (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80))];
    }

    private function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\t", "\u{00A0}"], ["\n", "\n", ' ', ' '], $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text);
        $text = preg_replace('/ *\n */', "\n", $text);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }
}
