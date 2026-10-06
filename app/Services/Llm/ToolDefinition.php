<?php

namespace App\Services\Llm;

/**
 * Herramienta expuesta al modelo, independiente del proveedor.
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema del input (type: object).
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $parameters,
    ) {}
}
