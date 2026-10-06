<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('last_message_at')->nullable()->after('window_expires_at');
            // Último mensaje incluido en el resumen; lo posterior va completo al contexto.
            $table->unsignedBigInteger('summarized_until_message_id')->nullable()->after('summary');
            $table->foreignId('assigned_user_id')->nullable()->after('agent_id')->constrained('users')->nullOnDelete();

            $table->index(['tenant_id', 'last_message_at']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('author')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'last_message_at']);
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn(['last_message_at', 'summarized_until_message_id']);
        });
    }
};
