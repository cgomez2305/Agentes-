<?php

namespace Tests\Feature\Agentes;

use App\Livewire\Settings\KnowledgeSettings;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeSource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\DocumentExtractor;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Feature\Agentes\Concerns\BuildsTenants;
use Tests\TestCase;

class DocumentKnowledgeTest extends TestCase
{
    use BuildsTenants, RefreshDatabase;

    private DocumentExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeLlm();
        $this->extractor = new DocumentExtractor;
        $this->extractor->resolver = fn (string $host) => match ($host) {
            'clinica.example' => ['93.184.216.34'],
            'interno.example' => ['10.0.0.5'],
            default => [],
        };
        $this->app->instance(DocumentExtractor::class, $this->extractor);
    }

    public function test_extracts_the_main_text_of_a_web_page(): void
    {
        Http::fake(['clinica.example/*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html; charset=utf-8'])]);

        $page = $this->extractor->fromUrl('https://clinica.example/preguntas');

        $this->assertSame('Preguntas frecuentes | Clínica', $page['title']);
        $this->assertStringContainsString('Hay parqueadero gratuito en el sótano.', $page['text']);
        $this->assertStringContainsString("Aceptamos Nequi y PSE.\n\n", $page['text']);
        $this->assertStringNotContainsString('Inicio', $page['text'], 'El menú se descarta');
        $this->assertStringNotContainsString('analytics', $page['text'], 'Los scripts se descartan');
    }

    public function test_blocks_internal_addresses_and_redirects_to_them(): void
    {
        foreach (['http://127.0.0.1/admin', 'http://169.254.169.254/latest/meta-data', 'https://interno.example/', 'file:///etc/passwd', 'https://no-existe.example/'] as $url) {
            try {
                $this->extractor->fromUrl($url);
                $this->fail("Debió bloquear {$url}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Http::fake(['clinica.example/*' => Http::response('', 302, ['Location' => 'https://interno.example/secreto'])]);
        $this->expectException(InvalidArgumentException::class);
        $this->extractor->fromUrl('https://clinica.example/ir');
    }

    public function test_extracts_text_from_a_pdf(): void
    {
        $path = $this->pdf('Horario: lunes a viernes de 8 a 6. Parqueadero gratuito.');

        $this->assertStringContainsString('Parqueadero gratuito', $this->extractor->fromPdf($path));
    }

    public function test_settings_page_adds_pdf_and_url_sources(): void
    {
        Http::fake(['clinica.example/*' => Http::response($this->page(), 200, ['Content-Type' => 'text/html'])]);
        $tenant = $this->makeTenant();
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        Livewire::actingAs($user)->test(KnowledgeSettings::class)
            ->set('showSourceForm', true)
            ->set('sourceMode', 'url')
            ->set('url', 'clinica.example/preguntas')
            ->call('addSource')
            ->assertHasNoErrors()
            ->set('sourceMode', 'pdf')
            ->set('pdf', UploadedFile::fake()->createWithContent('brochure.pdf', file_get_contents($this->pdf('Blanqueamiento en una sola sesion de 90 minutos.'))))
            ->call('addSource')
            ->assertHasNoErrors()
            ->set('sourceMode', 'url')
            ->set('url', 'http://127.0.0.1')
            ->call('addSource')
            ->assertHasErrors(['url']);

        $sources = $this->inTenant($tenant, fn () => KnowledgeSource::orderBy('id')->get());
        $this->assertSame(['url', 'pdf'], $sources->pluck('type')->all());
        $this->assertSame('https://clinica.example/preguntas', $sources[0]->origin);
        $this->assertSame('brochure', $sources[1]->title);
        $this->assertTrue($this->inTenant($tenant, fn () => KnowledgeChunk::where('content', 'like', '%sola sesion%')->exists()));
    }

    private function page(): string
    {
        return <<<'HTML'
        <!doctype html><html><head><title>Preguntas frecuentes | Clínica</title><script>analytics()</script></head>
        <body><nav><a>Inicio</a><a>Servicios</a></nav>
        <main><h1>Preguntas frecuentes</h1><p>Hay parqueadero gratuito en el sótano.</p><p>Aceptamos Nequi y PSE.</p>
        <ul><li>Valoración: 30 minutos</li></ul></main><footer>© 2026</footer></body></html>
        HTML;
    }

    /**
     * PDF mínimo válido con una línea de texto (sin dependencias).
     */
    private function pdf(string $text): string
    {
        $stream = 'BT /F1 12 Tf 72 720 Td ('.addcslashes($text, '()\\').') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        $path = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
        file_put_contents($path, $pdf);

        return $path;
    }

    private function inTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->run($tenant, $callback);
    }
}
