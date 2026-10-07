<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->unsignedSmallInteger('party_size');
            $table->dateTime('reserved_at');
            $table->unsignedSmallInteger('duration_minutes')->default(90);
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('confirmed');
            $table->string('source', 20)->default('staff');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('order_uuid')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('reserved_at');
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->timestamp('occurred_at');
            $table->timestamp('received_at');
            $table->string('source', 20);
            $table->foreignId('device_id')->nullable()->constrained()->restrictOnDelete();
            $table->boolean('synced_late')->default(false);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('hash', 64);
            $table->string('previous_hash', 64)->nullable();
            $table->index(['user_id', 'occurred_at']);
        });

        Schema::create('time_entry_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('time_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('action', 10);
            $table->string('original_type', 20)->nullable();
            $table->timestamp('original_occurred_at')->nullable();
            $table->string('new_type', 20)->nullable();
            $table->timestamp('new_occurred_at')->nullable();
            $table->text('reason');
            $table->foreignId('corrected_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->string('hash', 64);
            $table->index(['user_id', 'new_occurred_at']);
        });

        $this->protectAppendOnly(['time_entries', 'time_entry_corrections']);

        Schema::create('shift_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->string('color', 9)->default('#00056a');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->foreignId('shift_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->index(['date', 'user_id']);
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('supplier')->nullable();
            $table->date('invoice_date')->nullable();
            $table->integer('amount')->nullable();
            $table->text('notes')->nullable();
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->string('original_name')->nullable();
            $table->unsignedInteger('size');
            $table->string('ocr_status', 20)->default('none');
            $table->json('ocr_data')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['invoice_date', 'supplier']);
        });
    }

    /**
     * @param  list<literal-string>  $tables
     */
    private function protectAppendOnly(array $tables): void
    {
        $driver = DB::getDriverName();

        foreach ($tables as $table) {
            if ($driver === 'pgsql') {
                DB::unprepared(<<<SQL
                    CREATE OR REPLACE FUNCTION {$table}_append_only() RETURNS trigger AS \$\$
                    BEGIN
                        RAISE EXCEPTION 'El registro de jornada es inmutable: no se permite % en {$table}', TG_OP;
                    END;
                    \$\$ LANGUAGE plpgsql;
                    CREATE TRIGGER {$table}_no_update BEFORE UPDATE OR DELETE ON {$table}
                        FOR EACH ROW EXECUTE FUNCTION {$table}_append_only();
                    CREATE TRIGGER {$table}_no_truncate BEFORE TRUNCATE ON {$table}
                        FOR EACH STATEMENT EXECUTE FUNCTION {$table}_append_only();
                SQL);
            } elseif ($driver === 'sqlite') {
                DB::unprepared("CREATE TRIGGER {$table}_no_update BEFORE UPDATE ON {$table} BEGIN SELECT RAISE(ABORT, 'El registro de jornada es inmutable'); END;");
                DB::unprepared("CREATE TRIGGER {$table}_no_delete BEFORE DELETE ON {$table} BEGIN SELECT RAISE(ABORT, 'El registro de jornada es inmutable'); END;");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('shift_templates');

        if (DB::getDriverName() === 'pgsql') {
            foreach (['time_entry_corrections', 'time_entries'] as $table) {
                DB::unprepared("DROP TABLE IF EXISTS {$table} CASCADE; DROP FUNCTION IF EXISTS {$table}_append_only();");
            }
        } else {
            Schema::dropIfExists('time_entry_corrections');
            Schema::dropIfExists('time_entries');
        }

        Schema::dropIfExists('reservations');
    }
};
