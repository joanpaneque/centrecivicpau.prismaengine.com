<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Windows print program: installed on a PC of the bar, it connects out to the server
 * and prints the jobs of the printers assigned to it, without any dialog. Printers say
 * which program prints them and how (ESC/POS bytes or the Windows driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('token_hash', 64)->unique();
            $table->string('channel', 40)->unique();
            $table->string('machine', 120)->nullable();
            $table->string('version', 20)->nullable();
            $table->json('system_printers')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->foreignId('print_agent_id')->nullable()->after('type')->constrained()->nullOnDelete();
            $table->string('output', 10)->default('escpos')->after('print_agent_id');
            $table->string('codepage', 10)->default('cp858')->after('output');
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('claimed_by_agent_id')->nullable()->after('claimed_by_device_id')->constrained('print_agents')->nullOnDelete();
            $table->string('error')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('claimed_by_agent_id');
            $table->dropColumn('error');
        });

        Schema::table('printers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('print_agent_id');
            $table->dropColumn(['output', 'codepage']);
        });

        Schema::dropIfExists('print_agents');
    }
};
