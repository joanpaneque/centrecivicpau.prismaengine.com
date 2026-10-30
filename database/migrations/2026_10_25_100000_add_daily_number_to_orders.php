<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order number of the day (1, 2, 3… from scratch every day), printed at the top of every
 * kitchen ticket so the second courses asked later match the first ticket. Given by the
 * server, so two devices never give the same one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable()->after('uuid');
        });

        Schema::create('order_counters', function (Blueprint $table) {
            $table->date('day')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_counters');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('number');
        });
    }
};
