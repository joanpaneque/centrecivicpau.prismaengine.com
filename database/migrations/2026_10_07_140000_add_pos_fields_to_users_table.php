<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
            $table->string('role', 20)->default('staff')->after('email');
            $table->string('locale', 5)->default('ca')->after('role');
            $table->string('pin_hash')->nullable()->after('password');
            $table->string('pin_digest', 64)->nullable()->after('pin_hash');
            $table->text('login_token')->nullable()->after('pin_hash');
            $table->string('login_token_hash', 64)->nullable()->unique()->after('login_token');
            $table->string('color', 9)->nullable()->after('locale');
            $table->string('tax_id', 20)->nullable()->after('color');
            $table->boolean('active')->default(true)->after('tax_id');
        });

        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['login_token_hash']);
            $table->dropColumn(['role', 'locale', 'pin_hash', 'pin_digest', 'login_token', 'login_token_hash', 'color', 'tax_id', 'active']);
        });
    }
};
