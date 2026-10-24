<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regular customers with a code of their own: their orders and tickets are assigned to
 * them, and at the end they get a summary ticket of everything (statement) whose QR asks
 * for one full invoice covering all those tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 160);
            $table->string('tax_id', 30)->nullable();
            $table->string('address', 200)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::create('customer_statements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('public_token', 64)->unique();
            $table->timestamp('issued_at');
            $table->unsignedInteger('total');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('reservation_uuid')->constrained()->nullOnDelete();
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('order_id')->constrained()->nullOnDelete();
            $table->foreignId('customer_statement_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->foreignId('ticket_id')->nullable()->change();
            $table->foreignId('customer_statement_id')->nullable()->unique()->after('ticket_id')->constrained();
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_statement_id');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_statement_id');
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        Schema::dropIfExists('customer_statements');
        Schema::dropIfExists('customers');
    }
};
