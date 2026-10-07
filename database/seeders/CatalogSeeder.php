<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductionDestination;
use Illuminate\Database\Seeder;

/**
 * Initial menu from the brief. Prices are indicative and editable from the back office.
 */
class CatalogSeeder extends Seeder
{
    /** @var array<string, int> */
    private array $destinations = [];

    public function run(): void
    {
        if (Category::query()->exists()) {
            return;
        }

        $this->destinations = ProductionDestination::query()->pluck('id', 'code')->all();

        $groups = $this->modifierGroups();

        $tree = [
            ['Cafès', 'Cafés', '#7c4a1e', 'bar', ['coffee'], [
                ['Cafè sol', 'Café solo', 130, ['milk' => false]],
                ['Tallat', 'Cortado', 140, ['milk']],
                ['Cafè amb llet', 'Café con leche', 170, ['milk']],
                ['Caputxino', 'Capuchino', 220, ['milk']],
                ['Rebentat', 'Carajillo', 180, []],
                ['Rebentat especial', 'Carajillo especial', 250, []],
                ['Cafè descafeïnat', 'Café descafeinado', 140, []],
                ['Tallat descafeïnat', 'Cortado descafeinado', 150, ['milk']],
                ['Infusió', 'Infusión', 160, []],
                ['Xocolata desfeta', 'Chocolate a la taza', 250, ['milk']],
            ]],
            ['Licors', 'Licores', '#9333ea', 'bar', [], [
                ['Ratafia', 'Ratafía', 300, []],
                ['Herbes', 'Hierbas', 300, []],
                ['Orujo', 'Orujo', 300, []],
                ['Pacharan', 'Pacharán', 350, []],
                ['Whisky', 'Whisky', 550, []],
                ['Rom', 'Ron', 550, []],
                ['Gintònic', 'Gin-tonic', 750, []],
                ['Vermut', 'Vermut', 300, ['sulphites']],
            ]],
            ['Refrescos', 'Refrescos', '#dc2626', 'bar', ['ice'], [
                ['Coca-Cola', 'Coca-Cola', 230, []],
                ['Coca-Cola Zero', 'Coca-Cola Zero', 230, []],
                ['Fanta de taronja', 'Fanta de naranja', 230, []],
                ['Fanta de llimona', 'Fanta de limón', 230, []],
                ['Aquarius', 'Aquarius', 240, []],
                ['Tònica', 'Tónica', 230, []],
                ['Aigua 50 cl', 'Agua 50 cl', 150, []],
                ['Aigua amb gas', 'Agua con gas', 180, []],
                ['Suc de taronja natural', 'Zumo de naranja natural', 320, []],
                ['Bitter Kas', 'Bitter Kas', 250, []],
            ]],
            ['Cerveses', 'Cervezas', '#d97706', 'bar', ['beer'], [
                ['Canya', 'Caña', 180, ['gluten']],
                ['Doble', 'Doble', 280, ['gluten']],
                ['Gerra', 'Jarra', 450, ['gluten']],
                ['Estrella Damm', 'Estrella Damm', 240, ['gluten']],
                ['Voll-Damm', 'Voll-Damm', 280, ['gluten']],
                ['Free Damm (sense alcohol)', 'Free Damm (sin alcohol)', 230, ['gluten']],
                ['Daura (sense gluten)', 'Daura (sin gluten)', 280, []],
                ['Moritz', 'Moritz', 250, ['gluten']],
                ['Moritz Epidor', 'Moritz Epidor', 290, ['gluten']],
                ['Inedit', 'Inedit', 380, ['gluten']],
                ['Clara', 'Clara', 200, ['gluten']],
                ['Cervesa artesana', 'Cerveza artesana', 400, ['gluten']],
            ]],
            ['Entrepans', 'Bocadillos', '#ca8a04', 'kitchen', ['bread'], [
                ['Entrepà de pernil', 'Bocadillo de jamón', 450, ['gluten']],
                ['Entrepà de formatge', 'Bocadillo de queso', 400, ['gluten', 'milk']],
                ['Entrepà de truita', 'Bocadillo de tortilla', 400, ['gluten', 'eggs']],
                ['Entrepà de botifarra', 'Bocadillo de butifarra', 500, ['gluten']],
                ['Entrepà de llom i formatge', 'Bocadillo de lomo y queso', 520, ['gluten', 'milk']],
                ['Bikini', 'Bikini', 350, ['gluten', 'milk']],
                ['Entrepà vegetal', 'Bocadillo vegetal', 450, ['gluten', 'eggs']],
            ]],
            ['Entrants', 'Entrantes', '#16a34a', 'kitchen', [], [
                ['Pa amb tomàquet', 'Pan con tomate', 250, ['gluten']],
                ['Amanida verda', 'Ensalada verde', 650, []],
                ['Patates braves', 'Patatas bravas', 550, ['eggs']],
                ['Croquetes casolanes', 'Croquetas caseras', 700, ['gluten', 'milk', 'eggs']],
                ['Calamars a la romana', 'Calamares a la romana', 900, ['gluten', 'molluscs', 'eggs']],
                ['Escalivada', 'Escalivada', 750, []],
            ]],
            ['Segons (carns)', 'Segundos (carnes)', '#b91c1c', 'kitchen', ['doneness', 'side'], [
                ['Entrecot', 'Entrecot', 1800, []],
                ['Botifarra amb mongetes', 'Butifarra con judías', 1100, []],
                ['Pollastre a la brasa', 'Pollo a la brasa', 1000, []],
                ['Hamburguesa de vedella', 'Hamburguesa de ternera', 1200, ['gluten', 'milk', 'sesame']],
                ['Costelles de xai', 'Costillas de cordero', 1600, []],
            ]],
            ['Peix', 'Pescado', '#0284c7', 'kitchen', ['side'], [
                ['Bacallà a la llauna', 'Bacalao a la llauna', 1500, ['fish']],
                ['Lluç a la planxa', 'Merluza a la plancha', 1400, ['fish']],
                ['Sípia a la planxa', 'Sepia a la plancha', 1300, ['molluscs']],
            ]],
            ['Per emportar', 'Para llevar', '#475569', 'kitchen', [], [
                ['Pollastre a l\'ast', 'Pollo asado', 1200, []],
                ['Ració de patates per emportar', 'Ración de patatas para llevar', 400, []],
                ['Entrepà per emportar', 'Bocadillo para llevar', 500, ['gluten']],
            ]],
            ['Tapes', 'Tapas', '#ea580c', 'kitchen', [], []],
            ['Menús', 'Menús', '#059669', null, [], []],
        ];

        foreach ($tree as $sort => [$ca, $es, $color, $destination, $groupKeys, $products]) {
            $category = Category::query()->create([
                'name' => ['ca' => $ca, 'es' => $es],
                'color' => $color,
                'production_destination_id' => $destination ? ($this->destinations[$destination] ?? null) : null,
                'is_menu' => $ca === 'Menús',
                'sort' => $sort + 1,
            ]);

            $category->modifierGroups()->sync(array_map(fn (string $key) => $groups[$key]->id, $groupKeys));
            $this->products($category, $products);
        }

        $tapas = Category::query()->where('name->ca', 'Tapes')->firstOrFail();

        $subcategories = [
            ['Fredes', 'Frías', [
                ['Olives', 'Aceitunas', 250, []],
                ['Anxoves de l\'Escala', 'Anchoas de L\'Escala', 750, ['fish']],
                ['Pernil ibèric', 'Jamón ibérico', 1200, []],
                ['Formatge curat', 'Queso curado', 800, ['milk']],
            ]],
            ['Calentes', 'Calientes', [
                ['Gambes a l\'all', 'Gambas al ajillo', 950, ['crustaceans']],
                ['Pop a la gallega', 'Pulpo a la gallega', 1300, ['molluscs']],
                ['Mandonguilles', 'Albóndigas', 750, ['gluten', 'eggs']],
            ]],
            ['Fregides', 'Fritas', [
                ['Rabes de calamar', 'Rabas de calamar', 850, ['gluten', 'molluscs']],
                ['Xipirons', 'Chipirones', 900, ['gluten', 'molluscs']],
                ['Pebrots del Padró', 'Pimientos del Padrón', 600, []],
            ]],
        ];

        foreach ($subcategories as $sort => [$ca, $es, $products]) {
            $sub = Category::query()->create([
                'parent_id' => $tapas->id,
                'name' => ['ca' => $ca, 'es' => $es],
                'sort' => $sort + 1,
            ]);
            $this->products($sub, $products);
        }
    }

    /**
     * @param  list<array{0: string, 1: string, 2: int, 3: array<int|string, mixed>}>  $products
     */
    private function products(Category $category, array $products): void
    {
        foreach ($products as $sort => [$ca, $es, $price, $allergens]) {
            Product::query()->create([
                'category_id' => $category->id,
                'name' => ['ca' => $ca, 'es' => $es],
                'price' => $price,
                'vat_rate' => 10,
                'allergens' => array_values(array_filter($allergens, 'is_string')),
                'sort' => $sort + 1,
            ]);
        }
    }

    /**
     * @return array<string, ModifierGroup>
     */
    private function modifierGroups(): array
    {
        $definitions = [
            'coffee' => [['Opcions del cafè', 'Opciones del café'], true, false, [
                ['Descafeïnat', 'Descafeinado', 0],
                ['Llet de civada', 'Leche de avena', 20],
                ['Llet sense lactosa', 'Leche sin lactosa', 0],
                ['Llet freda', 'Leche fría', 0],
                ['Amb gel', 'Con hielo', 0],
                ['En got', 'En vaso', 0],
                ['Molt calent', 'Muy caliente', 0],
                ['Sacarina', 'Sacarina', 0],
            ]],
            'ice' => [['Servei', 'Servicio'], true, false, [
                ['Amb gel', 'Con hielo', 0],
                ['Sense gel', 'Sin hielo', 0],
                ['Amb llimona', 'Con limón', 0],
                ['Del temps', 'Del tiempo', 0],
            ]],
            'beer' => [['Servei cervesa', 'Servicio cerveza'], true, false, [
                ['Ben freda', 'Muy fría', 0],
                ['Amb llimona', 'Con limón', 20],
                ['Got gelat', 'Vaso helado', 0],
            ]],
            'bread' => [['Pa', 'Pan'], false, false, [
                ['Pa blanc', 'Pan blanco', 0],
                ['Pa integral', 'Pan integral', 0],
                ['Pa sense gluten', 'Pan sin gluten', 100],
                ['Amb tomàquet', 'Con tomate', 0],
                ['Calent', 'Caliente', 0],
            ]],
            'doneness' => [['Punt de la carn', 'Punto de la carne'], false, false, [
                ['Poc fet', 'Poco hecho', 0],
                ['Al punt', 'Al punto', 0],
                ['Ben fet', 'Muy hecho', 0],
            ]],
            'side' => [['Guarnició', 'Guarnición'], false, false, [
                ['Patates fregides', 'Patatas fritas', 0],
                ['Amanida', 'Ensalada', 0],
                ['Verdures a la brasa', 'Verduras a la brasa', 100],
                ['Sense guarnició', 'Sin guarnición', 0],
            ]],
        ];

        $groups = [];
        $sort = 0;

        foreach ($definitions as $key => [[$ca, $es], $multiple, $required, $modifiers]) {
            $group = ModifierGroup::query()->create([
                'name' => ['ca' => $ca, 'es' => $es],
                'multiple' => $multiple,
                'required' => $required,
                'sort' => ++$sort,
            ]);

            foreach ($modifiers as $index => [$mca, $mes, $delta]) {
                $group->modifiers()->create([
                    'name' => ['ca' => $mca, 'es' => $mes],
                    'price_delta' => $delta,
                    'sort' => $index + 1,
                ]);
            }

            $groups[$key] = $group;
        }

        return $groups;
    }
}
