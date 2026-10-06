<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('wa_id'); // teléfono en formato E.164 sin "+"
            $table->string('name')->nullable();
            $table->string('stage')->default('nuevo'); // nuevo, calificado, cliente, perdido
            $table->json('tags')->nullable();
            $table->json('lead_data')->nullable();
            $table->boolean('opted_out')->default(false);
            $table->timestamps();

            $table->unique(['tenant_id', 'wa_id']);
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('bot'); // bot, human, closed
            $table->text('summary')->nullable();
            $table->string('handoff_reason')->nullable();
            $table->timestamp('last_inbound_at')->nullable();
            // Fin de la ventana de servicio de 24 h de Meta.
            $table->timestamp('window_expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('direction'); // in, out
            $table->string('author')->default('contact'); // contact, bot, human, system
            $table->string('type')->default('text');
            $table->text('content')->nullable();
            $table->string('wa_message_id')->nullable()->unique();
            $table->string('status')->nullable(); // sent, delivered, read, failed, draft
            // De dónde salió la respuesta: rule, llm, human.
            $table->string('source')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
        });

        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // YYYY-MM
            $table->unsignedInteger('conversations')->default(0);
            $table->unsignedInteger('llm_calls')->default(0);
            $table->unsignedInteger('rule_replies')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->timestamps();

            $table->unique(['tenant_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('contacts');
    }
};
