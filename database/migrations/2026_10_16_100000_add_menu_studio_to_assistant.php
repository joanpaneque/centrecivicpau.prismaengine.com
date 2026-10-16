<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Carta amb IA": conversations are either help questions or catalog/menu work, and the
 * assistant's replies keep what it did (actions, previews, suggested answers) as metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->string('kind', 10)->default('help')->after('user_id');
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('meta');
        });

        Schema::table('assistant_conversations', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
