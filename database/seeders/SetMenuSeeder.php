<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\ProductionDestination;
use App\Models\SetMenu;
use Illuminate\Database\Seeder;

class SetMenuSeeder extends Seeder
{
    public function run(): void
    {
        if (SetMenu::query()->exists()) {
            return;
        }

        $kitchen = ProductionDestination::query()->where('code', 'kitchen')->value('id');
        $bar = ProductionDestination::query()->where('code', 'bar')->value('id');
        $product = fn (string $ca) => Product::query()->where('name->ca', $ca)->value('id');

        $this->menu(
            name: ['ca' => 'Menú del dia', 'es' => 'Menú del día'],
            includes: ['ca' => 'Pa, beguda i postres o cafè', 'es' => 'Pan, bebida y postre o café'],
            price: 1450,
            schedule: ['schedule_type' => 'weekdays', 'weekdays' => [1, 2, 3, 4, 5]],
            sections: [
                [['ca' => 'Primers', 'es' => 'Primeros'], 1, 1, [
                    [null, ['ca' => 'Amanida de la casa', 'es' => 'Ensalada de la casa'], $kitchen, 0],
                    [null, ['ca' => 'Macarrons a la bolonyesa', 'es' => 'Macarrones a la boloñesa'], $kitchen, 0],
                    [null, ['ca' => 'Crema de carbassa', 'es' => 'Crema de calabaza'], $kitchen, 0],
                    [$product('Escalivada'), null, null, 0],
                ]],
                [['ca' => 'Segons', 'es' => 'Segundos'], 1, 2, [
                    [$product('Pollastre a la brasa'), null, null, 0],
                    [$product('Lluç a la planxa'), null, null, 0],
                    [$product('Botifarra amb mongetes'), null, null, 0],
                    [$product('Entrecot'), null, null, 500],
                ]],
                [['ca' => 'Postres', 'es' => 'Postre'], 1, 3, [
                    [null, ['ca' => 'Crema catalana', 'es' => 'Crema catalana'], $kitchen, 0],
                    [null, ['ca' => 'Fruita del temps', 'es' => 'Fruta del tiempo'], $kitchen, 0],
                    [null, ['ca' => 'Iogurt', 'es' => 'Yogur'], $kitchen, 0],
                    [$product('Cafè sol'), null, null, 0],
                ]],
                [['ca' => 'Beguda', 'es' => 'Bebida'], 1, null, [
                    [null, ['ca' => 'Aigua', 'es' => 'Agua'], $bar, 0],
                    [null, ['ca' => 'Vi negre de la casa', 'es' => 'Vino tinto de la casa'], $bar, 0],
                    [null, ['ca' => 'Refresc', 'es' => 'Refresco'], $bar, 0],
                    [$product('Canya'), null, null, 0],
                ]],
            ],
            sort: 1,
        );

        $this->menu(
            name: ['ca' => 'Menú de cap de setmana', 'es' => 'Menú de fin de semana'],
            includes: ['ca' => 'Pa, beguda i postres', 'es' => 'Pan, bebida y postre'],
            price: 2200,
            schedule: ['schedule_type' => 'weekdays', 'weekdays' => [6, 7]],
            sections: [
                [['ca' => 'Primers', 'es' => 'Primeros'], 1, 1, [
                    [$product('Croquetes casolanes'), null, null, 0],
                    [null, ['ca' => 'Arròs negre', 'es' => 'Arroz negro'], $kitchen, 0],
                    [$product('Amanida verda'), null, null, 0],
                ]],
                [['ca' => 'Segons', 'es' => 'Segundos'], 1, 2, [
                    [$product('Costelles de xai'), null, null, 0],
                    [$product('Bacallà a la llauna'), null, null, 0],
                    [$product('Entrecot'), null, null, 300],
                ]],
                [['ca' => 'Postres', 'es' => 'Postre'], 1, 3, [
                    [null, ['ca' => 'Crema catalana', 'es' => 'Crema catalana'], $kitchen, 0],
                    [null, ['ca' => 'Pastís de formatge', 'es' => 'Tarta de queso'], $kitchen, 0],
                ]],
                [['ca' => 'Beguda', 'es' => 'Bebida'], 1, null, [
                    [null, ['ca' => 'Aigua', 'es' => 'Agua'], $bar, 0],
                    [null, ['ca' => 'Vi negre de la casa', 'es' => 'Vino tinto de la casa'], $bar, 0],
                ]],
            ],
            sort: 2,
        );

        $this->menu(
            name: ['ca' => 'Menú compartit (per persona)', 'es' => 'Menú compartido (por persona)'],
            includes: ['ca' => 'Entrants al centre, segon individual i postres', 'es' => 'Entrantes al centro, segundo individual y postre'],
            price: 2500,
            schedule: ['schedule_type' => 'always'],
            sections: [
                [['ca' => 'Entrants al centre', 'es' => 'Entrantes al centro'], 2, 1, [
                    [$product('Patates braves'), null, null, 0],
                    [$product('Calamars a la romana'), null, null, 0],
                    [$product('Pa amb tomàquet'), null, null, 0],
                    [$product('Pernil ibèric'), null, null, 300],
                ]],
                [['ca' => 'Segon', 'es' => 'Segundo'], 1, 2, [
                    [$product('Entrecot'), null, null, 0],
                    [$product('Lluç a la planxa'), null, null, 0],
                ]],
                [['ca' => 'Postres', 'es' => 'Postre'], 1, 3, [
                    [null, ['ca' => 'Crema catalana', 'es' => 'Crema catalana'], $kitchen, 0],
                    [null, ['ca' => 'Sorbet de llimona', 'es' => 'Sorbete de limón'], $kitchen, 0],
                ]],
            ],
            sort: 3,
        );
    }

    /**
     * @param  array{ca: string, es: string}  $name
     * @param  array{ca: string, es: string}  $includes
     * @param  array<string, mixed>  $schedule
     * @param  list<array{0: array{ca: string, es: string}, 1: int, 2: int|null, 3: list<array{0: mixed, 1: array{ca: string, es: string}|null, 2: mixed, 3: int}>}>  $sections
     */
    private function menu(array $name, array $includes, int $price, array $schedule, array $sections, int $sort): void
    {
        $menu = SetMenu::query()->create([
            'name' => $name,
            'includes' => $includes,
            'price' => $price,
            'vat_rate' => 10,
            'color' => '#059669',
            'sort' => $sort,
            ...$schedule,
        ]);

        foreach ($sections as $index => [$sectionName, $choices, $course, $items]) {
            $section = $menu->sections()->create([
                'name' => $sectionName,
                'choices' => $choices,
                'course' => $course,
                'sort' => $index + 1,
            ]);

            foreach ($items as $itemIndex => [$productId, $itemName, $destination, $supplement]) {
                $section->items()->create([
                    'product_id' => is_numeric($productId) ? (int) $productId : null,
                    'name' => $itemName,
                    'production_destination_id' => is_numeric($destination) ? (int) $destination : null,
                    'supplement' => $supplement,
                    'sort' => $itemIndex + 1,
                ]);
            }
        }
    }
}
