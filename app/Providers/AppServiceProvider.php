<?php

namespace App\Providers;

use Anthropic\Client;
use App\Services\Llm\AnthropicProvider;
use App\Services\Llm\LlmProvider;
use App\Support\TenantContext;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Un contexto de tenant por petición o job (Octane y workers lo reinician).
        $this->app->scoped(TenantContext::class);

        $this->app->singleton(LlmProvider::class, function () {
            return match (config('agentes.llm.provider')) {
                'anthropic' => new AnthropicProvider(new Client(apiKey: config('agentes.llm.anthropic.api_key'))),
                default => throw new InvalidArgumentException('Proveedor de LLM no soportado: '.config('agentes.llm.provider')),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
