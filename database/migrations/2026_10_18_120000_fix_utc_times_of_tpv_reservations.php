<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reservations made from the TPV were saved with their UTC time as if it were Madrid time
 * (two hours early in summer, one in winter). Now times are converted on arrival
 * (App\Support\ClientTime); this puts the existing ones at the time that was chosen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift(fn (string $value) => CarbonImmutable::parse($value, 'UTC')->setTimezone((string) config('app.timezone')));
    }

    public function down(): void
    {
        $this->shift(fn (string $value) => CarbonImmutable::parse($value, (string) config('app.timezone'))->setTimezone('UTC'));
    }

    /**
     * @param  Closure(string): CarbonImmutable  $convert
     */
    private function shift(Closure $convert): void
    {
        DB::table('reservations')->where('source', 'staff')->orderBy('id')->select(['id', 'reserved_at'])->chunkById(200, function ($rows) use ($convert) {
            foreach ($rows as $row) {
                DB::table('reservations')->where('id', $row->id)->update(['reserved_at' => $convert((string) $row->reserved_at)->format('Y-m-d H:i:s')]);
            }
        });
    }
};
