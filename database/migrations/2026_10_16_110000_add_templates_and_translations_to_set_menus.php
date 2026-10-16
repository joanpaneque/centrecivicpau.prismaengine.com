<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printable menus: each set menu remembers its design (classic, Halloween, Christmas,
 * Easter, kids) and keeps its texts translated to the printing languages.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('set_menus', function (Blueprint $table) {
            $table->string('template', 20)->default('classic')->after('color');
            $table->json('translations')->nullable()->after('template');
        });
    }

    public function down(): void
    {
        Schema::table('set_menus', function (Blueprint $table) {
            $table->dropColumn(['template', 'translations']);
        });
    }
};
