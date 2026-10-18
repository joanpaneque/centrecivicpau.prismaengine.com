<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each TPV device can print its tickets on its own printer (the cashier at the bar on the
 * bar printer, the terrace tablet on another). Null = the general ticket printer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('ticket_printer_id')->nullable()->after('type')->constrained('printers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_printer_id');
        });
    }
};
