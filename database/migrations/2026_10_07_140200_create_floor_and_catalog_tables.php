<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('slug')->unique();
            $table->boolean('applies_terrace_surcharge')->default(false);
            $table->boolean('is_bar')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zone_id')->constrained()->cascadeOnDelete();
            $table->string('label', 20);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->integer('x')->default(0);
            $table->integer('y')->default(0);
            $table->unsignedSmallInteger('width')->default(90);
            $table->unsignedSmallInteger('height')->default(90);
            $table->string('shape', 10)->default('square');
            $table->boolean('is_auxiliary')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('production_destinations', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->string('code', 30)->unique();
            $table->string('mode', 10)->default('printer');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20);
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('paper_width')->default(48);
            $table->boolean('is_ticket_printer')->default(false);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('printer_production_destination', function (Blueprint $table) {
            $table->foreignId('printer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_destination_id')->constrained()->cascadeOnDelete();
            $table->primary(['printer_id', 'production_destination_id']);
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->json('name');
            $table->string('color', 9)->nullable();
            $table->foreignId('production_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_menu')->default(false);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->unsignedInteger('price')->default(0);
            $table->decimal('vat_rate', 5, 2)->default(10);
            $table->string('photo_path')->nullable();
            $table->string('color', 9)->nullable();
            $table->json('allergens')->nullable();
            $table->foreignId('production_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('active')->default(true);
            $table->boolean('sold_out')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->unsignedInteger('order_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->boolean('multiple')->default(true);
            $table->boolean('required')->default(false);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->integer('price_delta')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('category_modifier_group', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['category_id', 'modifier_group_id']);
        });

        Schema::create('modifier_group_product', function (Blueprint $table) {
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->primary(['modifier_group_id', 'product_id']);
        });

        Schema::create('set_menus', function (Blueprint $table) {
            $table->id();
            $table->json('name');
            $table->json('includes')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->decimal('vat_rate', 5, 2)->default(10);
            $table->string('color', 9)->nullable();
            $table->string('schedule_type', 10)->default('always');
            $table->json('weekdays')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('set_menu_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_menu_id')->constrained()->cascadeOnDelete();
            $table->json('name');
            $table->unsignedTinyInteger('choices')->default(1);
            $table->unsignedSmallInteger('course')->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('set_menu_section_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('set_menu_section_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name')->nullable();
            $table->foreignId('production_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('supplement')->default(0);
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'set_menu_section_items', 'set_menu_sections', 'set_menus', 'modifier_group_product',
            'category_modifier_group', 'modifiers', 'modifier_groups', 'products', 'categories',
            'printer_production_destination', 'printers', 'production_destinations', 'dining_tables', 'zones',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
