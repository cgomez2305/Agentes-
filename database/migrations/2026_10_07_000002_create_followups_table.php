<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // {"nudge_enabled": true, "nudge_after_hours": 4, "reminder_enabled": true, "reminder_hours_before": 24, "reminder_template": "recordatorio_cita", "template_language": "es"}
            $table->json('followup_settings')->nullable()->after('booking_settings');
        });

        Schema::create('followups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind'); // nudge, reminder
            // Lo que originó el seguimiento (id del último mensaje sin respuesta o de la cita): evita repetirlo.
            $table->string('anchor');
            $table->string('status')->default('sent'); // sent, skipped, failed
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'kind', 'anchor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('followups');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('followup_settings');
        });
    }
};
