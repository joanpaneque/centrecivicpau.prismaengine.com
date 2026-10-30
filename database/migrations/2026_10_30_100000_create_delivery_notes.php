<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery notes (albarans): what a customer takes now and pays later. The order closes
 * without a payment; later the pending notes are charged together into a normal ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('customer_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->string('table_label')->nullable();
            $table->json('lines');
            $table->decimal('surcharge_rate', 5, 2)->default(0);
            $table->unsignedInteger('surcharge_amount')->default(0);
            $table->unsignedInteger('total');
            $table->json('vat_breakdown');
            // The order that charges it (open while being charged) and the ticket that did.
            $table->foreignId('collect_order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('ticket_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('collected_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'ticket_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
    }
};
