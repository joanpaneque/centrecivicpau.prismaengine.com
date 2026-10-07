<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open');
            $table->unsignedSmallInteger('guests')->nullable();
            $table->string('label')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('bill_requested_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->uuid('reservation_uuid')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_line_id')->nullable()->constrained('order_lines')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('set_menu_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('production_destination_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->integer('unit_price');
            $table->decimal('vat_rate', 5, 2)->default(10);
            $table->json('modifiers')->nullable();
            $table->string('note')->nullable();
            $table->unsignedTinyInteger('course')->nullable();
            $table->string('discount_type', 10)->nullable();
            $table->unsignedInteger('discount_value')->default(0);
            $table->string('discount_reason')->nullable();
            $table->foreignId('discounted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason')->nullable();
            $table->unsignedInteger('paid_quantity')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_destination_id')->constrained()->cascadeOnDelete();
            $table->string('table_label')->nullable();
            $table->unsignedTinyInteger('course')->nullable();
            $table->boolean('held')->default(false);
            $table->string('status', 20)->default('pending');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('served_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('kitchen_ticket_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kitchen_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->unsignedInteger('quantity');
            $table->json('modifiers')->nullable();
            $table->string('note')->nullable();
            $table->boolean('voided')->default(false);
        });

        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('printer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('printer_name')->nullable();
            $table->string('kind', 20);
            $table->string('title');
            $table->json('document');
            $table->string('status', 20)->default('simulated');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('kitchen_ticket_items');
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
