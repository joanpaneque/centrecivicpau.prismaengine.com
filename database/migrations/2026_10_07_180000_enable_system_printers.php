<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('system_name')->nullable()->after('model');
        });

        DB::table('printers')->where('type', 'simulated')->update([
            'type' => 'system',
            'system_name' => DB::raw('name'),
        ]);

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('claimed_by_device_id')->nullable()->after('device_id')->constrained('devices')->nullOnDelete();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropIndex(['status', 'id']);
            $table->dropConstrainedForeignId('claimed_by_device_id');
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->dropColumn('system_name');
        });
    }
};
