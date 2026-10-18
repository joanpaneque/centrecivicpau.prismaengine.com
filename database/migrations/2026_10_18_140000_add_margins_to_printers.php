<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blank lines added above and below every document a printer prints (the kitchen wants a
 * little room around its orders). The bottom ones go before the paper cut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->unsignedTinyInteger('margin_top')->default(0)->after('paper_width');
            $table->unsignedTinyInteger('margin_bottom')->default(0)->after('margin_top');
        });
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn(['margin_top', 'margin_bottom']);
        });
    }
};
