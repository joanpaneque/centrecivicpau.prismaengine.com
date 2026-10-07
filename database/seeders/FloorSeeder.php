<?php

namespace Database\Seeders;

use App\Models\DiningTable;
use App\Models\Zone;
use Illuminate\Database\Seeder;

class FloorSeeder extends Seeder
{
    public function run(): void
    {
        $zones = [
            ['slug' => 'terrassa', 'name' => ['ca' => 'Terrassa exterior', 'es' => 'Terraza exterior'], 'tables' => 30, 'columns' => 6, 'surcharge' => true, 'bar' => false],
            ['slug' => 'porxo', 'name' => ['ca' => 'Porxo', 'es' => 'Porche'], 'tables' => 10, 'columns' => 5, 'surcharge' => false, 'bar' => false],
            ['slug' => 'menjador', 'name' => ['ca' => 'Menjador', 'es' => 'Comedor'], 'tables' => 20, 'columns' => 5, 'surcharge' => false, 'bar' => false],
            ['slug' => 'barra', 'name' => ['ca' => 'Barra', 'es' => 'Barra'], 'tables' => 7, 'columns' => 7, 'surcharge' => false, 'bar' => true],
        ];

        foreach ($zones as $index => $data) {
            $zone = Zone::query()->updateOrCreate(['slug' => $data['slug']], [
                'name' => $data['name'],
                'applies_terrace_surcharge' => $data['surcharge'],
                'is_bar' => $data['bar'],
                'sort' => $index + 1,
            ]);

            if ($zone->tables()->exists()) {
                continue;
            }

            for ($n = 1; $n <= $data['tables']; $n++) {
                $col = ($n - 1) % $data['columns'];
                $row = intdiv($n - 1, $data['columns']);

                DiningTable::query()->create([
                    'zone_id' => $zone->id,
                    'label' => $data['bar'] ? 'B'.$n : (string) $n,
                    'seats' => $data['bar'] ? 1 : 4,
                    'x' => $data['bar'] ? 60 + $col * 125 : 40 + $col * 155,
                    'y' => $data['bar'] ? 260 : 30 + $row * 125,
                    'width' => $data['bar'] ? 80 : 100,
                    'height' => $data['bar'] ? 80 : 100,
                    'shape' => $data['bar'] ? 'stool' : ($n % 3 === 0 ? 'round' : 'square'),
                    'sort' => $n,
                ]);
            }
        }
    }
}
