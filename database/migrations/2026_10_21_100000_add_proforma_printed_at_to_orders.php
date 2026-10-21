<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the proforma of an order was printed, so the floor plan can paint that table apart.
 * Cleared as soon as new lines are sent or the order is reopened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('proforma_printed_at')->nullable()->after('bill_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('proforma_printed_at');
        });
    }
};
