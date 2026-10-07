<?php

namespace Database\Seeders;

use App\Models\ShiftTemplate;
use Illuminate\Database\Seeder;

class ShiftTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            ['Matí / Mañana', '07:00', '15:00', 30, '#0284c7'],
            ['Tarda / Tarde', '15:00', '23:00', 30, '#9333ea'],
            ['Partit / Partido', '11:00', '16:00', 0, '#16a34a'],
            ['Nit / Noche', '19:00', '01:00', 0, '#334155'],
        ];

        foreach ($templates as $sort => [$name, $start, $end, $break, $color]) {
            ShiftTemplate::query()->firstOrCreate(['name' => $name], [
                'start_time' => $start,
                'end_time' => $end,
                'break_minutes' => $break,
                'color' => $color,
                'sort' => $sort + 1,
            ]);
        }
    }
}
