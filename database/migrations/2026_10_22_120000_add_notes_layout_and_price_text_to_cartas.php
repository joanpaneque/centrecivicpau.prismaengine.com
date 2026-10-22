<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartas: small notes at the bottom (a legend), layout options (fit in N sheets, extra CSS
 * written by the assistant or by hand) and dishes priced with a text instead of euros
 * ("S.M." = según mercado).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cartas', function (Blueprint $table) {
            $table->json('notes')->nullable()->after('subtitle');
            $table->json('layout')->nullable()->after('template');
        });

        Schema::table('carta_items', function (Blueprint $table) {
            $table->string('price_text', 20)->nullable()->after('price');
        });
    }

    public function down(): void
    {
        Schema::table('carta_items', function (Blueprint $table) {
            $table->dropColumn('price_text');
        });

        Schema::table('cartas', function (Blueprint $table) {
            $table->dropColumn(['notes', 'layout']);
        });
    }
};
