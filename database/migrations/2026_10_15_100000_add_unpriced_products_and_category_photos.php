<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products can be created without a price (it is set during service), families can show
 * an image, and order lines remember that they were taken without a price so the cashier
 * asks for it before charging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price')->nullable()->default(null)->change();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('color');
        });

        Schema::table('order_lines', function (Blueprint $table) {
            $table->boolean('unpriced')->default(false)->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn('unpriced');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        DB::table('products')->whereNull('price')->update(['price' => 0]);

        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('price')->default(0)->nullable(false)->change();
        });
    }
};
