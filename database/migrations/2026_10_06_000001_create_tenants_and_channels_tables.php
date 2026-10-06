<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('vertical')->nullable();
            $table->string('plan')->default('prueba');
            $table->unsignedInteger('monthly_conversation_limit')->default(500);
            $table->string('timezone')->default('America/Bogota');
            $table->string('locale', 10)->default('es_CO');
            // Horario de atención: {"mon": ["08:00", "18:00"], ...}
            $table->json('business_hours')->nullable();
            // Datos fijos del negocio que el agente puede citar (dirección, teléfono, web...)
            $table->json('profile')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->string('role')->default('owner')->after('email');
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('whatsapp');
            $table->string('waba_id')->nullable();
            $table->string('phone_number_id')->unique();
            $table->string('display_phone')->nullable();
            $table->text('access_token');
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropColumn('role');
        });

        Schema::dropIfExists('tenants');
    }
};
