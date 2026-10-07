<?php

namespace Database\Seeders;

use App\Models\Printer;
use App\Models\ProductionDestination;
use Illuminate\Database\Seeder;

class PrintingSeeder extends Seeder
{
    public function run(): void
    {
        $bar = ProductionDestination::query()->updateOrCreate(['code' => 'bar'], [
            'name' => ['ca' => 'Barra', 'es' => 'Barra'],
            'mode' => 'printer',
            'sort' => 1,
        ]);

        $kitchen = ProductionDestination::query()->updateOrCreate(['code' => 'kitchen'], [
            'name' => ['ca' => 'Cuina', 'es' => 'Cocina'],
            'mode' => 'both',
            'sort' => 2,
        ]);

        $barPrinter = Printer::query()->updateOrCreate(['name' => 'Impressora barra'], [
            'type' => 'simulated',
            'paper_width' => 48,
            'is_ticket_printer' => false,
        ]);
        $barPrinter->destinations()->sync([$bar->id]);

        $kitchenPrinter = Printer::query()->updateOrCreate(['name' => 'Impressora cuina'], [
            'type' => 'simulated',
            'paper_width' => 48,
            'is_ticket_printer' => false,
        ]);
        $kitchenPrinter->destinations()->sync([$kitchen->id]);

        Printer::query()->updateOrCreate(['name' => 'Impressora tiquets'], [
            'type' => 'simulated',
            'paper_width' => 48,
            'is_ticket_printer' => true,
        ]);
    }
}
