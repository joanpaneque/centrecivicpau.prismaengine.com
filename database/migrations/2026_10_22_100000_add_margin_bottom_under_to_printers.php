<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bottom margin only for short tickets: added when the document is shorter than this
 * many lines (null = always), so a two-line order is long enough to tear off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedSmallInteger('margin_bottom_under')->nullable()->after('margin_bottom');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('margin_bottom_under');
        });
    }
};
