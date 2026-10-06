<?php

namespace App\Services\Llm;

interface LlmProvider
{
    /**
     * Ejecuta un turno completo: llama al modelo, ejecuta las herramientas que
     * pida con $executeTool y repite hasta obtener la respuesta final.
     *
     * @param  callable(string $name, array<string, mixed> $input): string  $executeTool
     */
    public function respond(LlmRequest $request, callable $executeTool): LlmResult;
}
