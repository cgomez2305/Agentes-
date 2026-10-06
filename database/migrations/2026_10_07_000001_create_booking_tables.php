<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // {"enabled": true, "slot_minutes": 30, "default_duration": 30, "min_notice_hours": 2, "max_days_ahead": 30, "capacity": 1}
            $table->json('booking_settings')->nullable()->after('business_hours');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalog_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            // Siempre en UTC; se muestran en la zona horaria del negocio.
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('status')->default('confirmed'); // confirmed, cancelled, completed, no_show
            $table->string('source')->default('agent'); // agent, human
            $table->text('notes')->nullable();
            $table->string('external_event_id')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'starts_at']);
        });

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider')->default('google');
            $table->string('account_email')->nullable();
            $table->string('calendar_id')->default('primary');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('appointments');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('booking_settings');
        });
    }
};
