<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Printable cartas, apart from the set menus: sections of dishes with a description and a
 * price each. A dish can point to a product of the POS catalog; without its own price it
 * shows the product's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartas', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('subtitle')->nullable();
            $table->string('template', 20)->default('classic');
            $table->json('translations')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('carta_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carta_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('carta_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carta_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name')->nullable();
            $table->json('description')->nullable();
            $table->integer('price')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carta_items');
        Schema::dropIfExists('carta_sections');
        Schema::dropIfExists('cartas');
    }
};
