<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The printers of the Windows print program are now set up in the program itself (name,
 * Windows printer or IP, format) and synced to the server, which matches them by this key.
 * The ones already assigned to a program get a key so the program adopts them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->string('agent_key', 40)->nullable()->after('print_agent_id');
            $table->index(['print_agent_id', 'agent_key']);
        });

        foreach (DB::table('printers')->whereNotNull('print_agent_id')->pluck('id') as $id) {
            DB::table('printers')->where('id', $id)->update(['agent_key' => (string) Str::uuid()]);
        }
    }

    public function down(): void
    {
        Schema::table('printers', function (Blueprint $table) {
            $table->dropIndex(['print_agent_id', 'agent_key']);
            $table->dropColumn('agent_key');
        });
    }
};
