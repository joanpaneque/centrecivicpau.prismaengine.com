<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dining_tables', function (Blueprint $table) {
            $table->smallInteger('rotation')->default(0)->after('height');
        });

        Schema::create('floor_elements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('label', 40)->nullable();
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedSmallInteger('width')->default(100);
            $table->unsignedSmallInteger('height')->default(100);
            $table->smallInteger('rotation')->default(0);
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floor_elements');

        Schema::table('dining_tables', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};
