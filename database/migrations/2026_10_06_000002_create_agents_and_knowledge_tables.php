<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('instructions');
            $table->string('tone')->default('cercano y profesional');
            // auto: responde solo; sugerir: deja un borrador para que un humano apruebe.
            $table->string('mode')->default('auto');
            $table->string('model_tier')->default('fast');
            // Datos que el agente debe obtener del lead: [{"key": "presupuesto", "question": "..."}]
            $table->json('qualification_fields')->nullable();
            // Respuestas deterministas sin LLM: [{"keywords": ["horario"], "reply": "..."}]
            $table->json('quick_replies')->nullable();
            $table->text('handoff_message')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('text'); // text, pdf, url, catalog
            $table->string('title');
            $table->string('origin')->nullable();
            $table->string('status')->default('ready');
            $table->timestamps();
        });

        Schema::create('knowledge_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_source_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            // Texto normalizado (minúsculas, sin tildes) para la búsqueda por palabras.
            // Las columnas de embeddings (pgvector) se agregan en una migración posterior.
            $table->text('search_text');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('catalog_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('service'); // product, service
            $table->string('sku')->nullable();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price')->nullable(); // en la unidad menor de la moneda (COP no usa decimales)
            $table->string('currency', 3)->default('COP');
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->boolean('is_available')->default(true);
            $table->string('search_text');
            $table->timestamps();

            $table->unique(['tenant_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_items');
        Schema::dropIfExists('knowledge_chunks');
        Schema::dropIfExists('knowledge_sources');
        Schema::dropIfExists('agents');
    }
};
