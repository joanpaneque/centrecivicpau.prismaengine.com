<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_series', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('kind', 20)->default('simplified');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('last_number')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->unsignedInteger('opening_float')->default(0);
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->json('cash_count')->nullable();
            $table->integer('counted_cash')->nullable();
            $table->integer('expected_cash')->nullable();
            $table->integer('difference')->nullable();
            $table->json('summary')->nullable();
            $table->unsignedInteger('z_number')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ticket_series_id')->constrained('ticket_series');
            $table->unsignedInteger('number');
            $table->string('full_number', 40);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('table_label')->nullable();
            $table->foreignId('waiter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->integer('subtotal');
            $table->decimal('surcharge_rate', 5, 2)->default(0);
            $table->integer('surcharge_amount')->default(0);
            $table->integer('discount_total')->default(0);
            $table->integer('total');
            $table->json('vat_breakdown');
            $table->json('issuer');
            $table->string('public_token', 64)->unique();
            $table->string('hash', 64)->nullable();
            $table->string('previous_hash', 64)->nullable();
            $table->json('verifactu')->nullable();
            $table->timestamps();
            $table->unique(['ticket_series_id', 'number']);
        });

        Schema::create('ticket_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->nullOnDelete();
            $table->json('name');
            $table->decimal('quantity', 8, 3);
            $table->integer('unit_price');
            $table->decimal('vat_rate', 5, 2);
            $table->integer('discount_amount')->default(0);
            $table->integer('total');
            $table->json('modifiers')->nullable();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('ticket_id')->constrained()->cascadeOnDelete();
            $table->string('method', 10);
            $table->integer('amount');
            $table->integer('tendered')->nullable();
            $table->integer('change')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained();
            $table->foreignId('ticket_series_id')->constrained('ticket_series');
            $table->unsignedInteger('number');
            $table->string('full_number', 40);
            $table->string('customer_name');
            $table->string('customer_tax_id', 30);
            $table->string('customer_address');
            $table->string('customer_email')->nullable();
            $table->timestamp('issued_at');
            $table->string('pdf_path')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['ticket_series_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('ticket_lines');
        Schema::dropIfExists('tickets');
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('ticket_series');
    }
};
