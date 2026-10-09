-- Carta del Centre Cívic Pau (products.md)
-- PostgreSQL. Pégalo entero en Adminer → SQL command → Execute.
--
-- Idempotente: si la categoría o el producto ya existen (mismo nombre en catalán),
-- actualiza precio, agotado y alérgenos. Si no existen, los crea.
-- Precios en céntimos (1,50 € = 150). IVA 10 % (hostelería).
-- Destinos: bar / kitchen (tienen que existir en production_destinations, códigos del seeder).
-- (NO DISP) = sold_out = true (se ve en el TPV como esgotat, se puede reactivar).

BEGIN;

CREATE TEMP TABLE tmp_carta (
    cat_ca text NOT NULL,
    cat_es text NOT NULL,
    cat_color text NOT NULL,
    dest_code text NOT NULL,
    cat_sort integer NOT NULL,
    prod_ca text NOT NULL,
    prod_es text NOT NULL,
    price_cents integer NOT NULL,
    sold_out boolean NOT NULL,
    allergens jsonb NOT NULL,
    prod_sort integer NOT NULL
);

INSERT INTO tmp_carta (cat_ca, cat_es, cat_color, dest_code, cat_sort, prod_ca, prod_es, price_cents, sold_out, allergens, prod_sort) VALUES
-- Cafès
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Cafè sol', 'Café solo', 150, false, '["milk"]'::jsonb, 1),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Tallat', 'Cortado', 160, false, '["milk"]'::jsonb, 2),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Cafè amb llet', 'Café con leche', 180, false, '["milk"]'::jsonb, 3),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Cafè doble', 'Café doble', 220, false, '["milk"]'::jsonb, 4),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Capuccino', 'Capuchino', 200, false, '["milk"]'::jsonb, 5),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Carajillo', 'Carajillo', 200, false, '[]'::jsonb, 6),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Carajillo superior', 'Carajillo superior', 220, false, '[]'::jsonb, 7),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Cacaolat', 'Cacaolat', 200, true, '["milk"]'::jsonb, 8),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Xocolata calenta', 'Chocolate caliente', 220, true, '["milk"]'::jsonb, 9),
('Cafès', 'Cafés', '#7c4a1e', 'bar', 1, 'Got de llet', 'Vaso de leche', 150, false, '["milk"]'::jsonb, 10),

-- Infusions
('Infusions', 'Infusiones', '#65a30d', 'bar', 2, 'Infusió general', 'Infusión general', 200, false, '[]'::jsonb, 1),

-- Begudes
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Aigua petita', 'Agua pequeña', 100, false, '[]'::jsonb, 1),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Aigua gran', 'Agua grande', 200, false, '[]'::jsonb, 2),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Aigua amb gas', 'Agua con gas', 180, false, '[]'::jsonb, 3),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Coca-Cola / Zero', 'Coca-Cola / Zero', 200, false, '[]'::jsonb, 4),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Fanta taronja / llimona', 'Fanta naranja / limón', 200, false, '[]'::jsonb, 5),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Nestea', 'Nestea', 200, false, '[]'::jsonb, 6),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Lipton', 'Lipton', 280, false, '[]'::jsonb, 7),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Trinaranjus', 'Trinaranjus', 250, false, '[]'::jsonb, 8),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Aquarius', 'Aquarius', 220, false, '[]'::jsonb, 9),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Tònica', 'Tónica', 250, false, '[]'::jsonb, 10),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Bitter Kas', 'Bitter Kas', 290, false, '[]'::jsonb, 11),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'La Casera', 'La Casera', 120, false, '[]'::jsonb, 12),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Suc de fruita', 'Zumo de fruta', 200, false, '[]'::jsonb, 13),
('Begudes', 'Bebidas', '#dc2626', 'bar', 3, 'Suc de taronja natural', 'Zumo de naranja natural', 300, true, '[]'::jsonb, 14),

-- Cerveses
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Canya petita', 'Caña pequeña', 180, false, '["gluten"]'::jsonb, 1),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Canya mitjana', 'Caña mediana', 220, false, '["gluten"]'::jsonb, 2),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Gerra', 'Jarra', 300, true, '["gluten"]'::jsonb, 3),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Quinto', 'Quinto', 180, false, '["gluten"]'::jsonb, 4),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Mitjana', 'Mediana', 250, false, '["gluten"]'::jsonb, 5),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Clara', 'Clara', 200, false, '["gluten"]'::jsonb, 6),
('Cerveses', 'Cervezas', '#d97706', 'bar', 4, 'Cervesa sense alcohol', 'Cerveza sin alcohol', 220, false, '["gluten"]'::jsonb, 7),

-- Vins i vermut
('Vins i vermut', 'Vinos y vermut', '#7e22ce', 'bar', 5, 'Copa de vi negre / blanc / rosat', 'Copa de vino tinto / blanco / rosado', 200, false, '["sulphites"]'::jsonb, 1),
('Vins i vermut', 'Vinos y vermut', '#7e22ce', 'bar', 5, 'Copa de cava', 'Copa de cava', 250, false, '["sulphites"]'::jsonb, 2),
('Vins i vermut', 'Vinos y vermut', '#7e22ce', 'bar', 5, 'Vermut', 'Vermut', 250, false, '["sulphites"]'::jsonb, 3),
('Vins i vermut', 'Vinos y vermut', '#7e22ce', 'bar', 5, 'Tinto de verano', 'Tinto de verano', 250, false, '["sulphites"]'::jsonb, 4),
('Vins i vermut', 'Vinos y vermut', '#7e22ce', 'bar', 5, 'Moscatell / Garnatxa de l''Empordà', 'Moscatel / Garnacha del Empordà', 250, false, '["sulphites"]'::jsonb, 5),

-- Licors i combinats
('Licors i combinats', 'Licores y combinados', '#9333ea', 'bar', 6, 'Xupito', 'Chupito', 150, false, '[]'::jsonb, 1),
('Licors i combinats', 'Licores y combinados', '#9333ea', 'bar', 6, 'Copa de licor (ratafia, herbes, whisky...)', 'Copa de licor (ratafía, hierbas, whisky...)', 350, false, '[]'::jsonb, 2),
('Licors i combinats', 'Licores y combinados', '#9333ea', 'bar', 6, 'Combinat', 'Combinado', 600, false, '[]'::jsonb, 3),
('Licors i combinats', 'Licores y combinados', '#9333ea', 'bar', 6, 'Gintònic', 'Gin-tonic', 700, false, '[]'::jsonb, 4),

-- Esmorzars
('Esmorzars', 'Desayunos', '#ca8a04', 'kitchen', 7, 'Pa amb tomàquet', 'Pan con tomate', 150, false, '["gluten"]'::jsonb, 1),
('Esmorzars', 'Desayunos', '#ca8a04', 'kitchen', 7, 'Torrada', 'Tostada', 200, false, '["gluten"]'::jsonb, 2),
('Esmorzars', 'Desayunos', '#ca8a04', 'kitchen', 7, 'Bikini', 'Bikini', 350, false, '["gluten","milk"]'::jsonb, 3),
('Esmorzars', 'Desayunos', '#ca8a04', 'kitchen', 7, 'Croissant', 'Croissant', 150, false, '["gluten","milk","eggs"]'::jsonb, 4),
('Esmorzars', 'Desayunos', '#ca8a04', 'kitchen', 7, 'Magdalena / Ensaïmada', 'Magdalena / Ensaimada', 150, false, '["gluten","eggs","milk"]'::jsonb, 5),

-- Per picar
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Patates xips', 'Patatas chips', 150, false, '[]'::jsonb, 1),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Olives', 'Aceitunas', 200, false, '[]'::jsonb, 2),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Fruits secs', 'Frutos secos', 200, false, '["nuts"]'::jsonb, 3),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Pinxo de truita', 'Pincho de tortilla', 250, false, '["eggs"]'::jsonb, 4),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Patates braves', 'Patatas bravas', 500, false, '["eggs"]'::jsonb, 5),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Croquetes', 'Croquetas', 600, false, '["gluten","milk","eggs"]'::jsonb, 6),
('Per picar', 'Para picar', '#ea580c', 'kitchen', 8, 'Plat d''embotit', 'Plato de embutido', 700, false, '[]'::jsonb, 7),

-- Entrepans
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà pernil salat', 'Bocadillo de jamón serrano', 400, false, '["gluten"]'::jsonb, 1),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de formatge', 'Bocadillo de queso', 500, false, '["gluten","milk"]'::jsonb, 2),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de tonyina', 'Bocadillo de atún', 400, false, '["gluten","fish"]'::jsonb, 3),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de bull', 'Bocadillo de bull', 400, false, '["gluten"]'::jsonb, 4),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de llom', 'Bocadillo de lomo', 450, false, '["gluten"]'::jsonb, 5),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de bacon', 'Bocadillo de bacon', 450, false, '["gluten"]'::jsonb, 6),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de butifarra', 'Bocadillo de butifarra', 500, false, '["gluten"]'::jsonb, 7),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Bikini', 'Bikini', 400, false, '["gluten","milk"]'::jsonb, 8),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Entrepà de frankfurt', 'Bocadillo de frankfurt', 400, false, '["gluten"]'::jsonb, 9),
('Entrepans', 'Bocadillos', '#b45309', 'kitchen', 9, 'Suplement entrepà', 'Suplemento bocadillo', 100, false, '[]'::jsonb, 10);

-- Categorías nuevas
INSERT INTO categories (name, color, production_destination_id, is_menu, active, sort, created_at, updated_at)
SELECT
    jsonb_build_object('ca', t.cat_ca, 'es', t.cat_es),
    t.cat_color,
    (SELECT id FROM production_destinations WHERE code = t.dest_code LIMIT 1),
    false,
    true,
    t.cat_sort,
    NOW(),
    NOW()
FROM (SELECT DISTINCT cat_ca, cat_es, cat_color, dest_code, cat_sort FROM tmp_carta) t
WHERE NOT EXISTS (
    SELECT 1 FROM categories c
    WHERE c.deleted_at IS NULL AND c.name->>'ca' = t.cat_ca
);

-- Alinear color, destino y orden de las que ya existían
UPDATE categories c
SET
    color = t.cat_color,
    production_destination_id = COALESCE(
        (SELECT id FROM production_destinations WHERE code = t.dest_code LIMIT 1),
        c.production_destination_id
    ),
    sort = t.cat_sort,
    active = true,
    updated_at = NOW()
FROM (SELECT DISTINCT cat_ca, cat_color, dest_code, cat_sort FROM tmp_carta) t
WHERE c.deleted_at IS NULL AND c.name->>'ca' = t.cat_ca;

-- Actualizar productos que ya están en esa categoría
UPDATE products p
SET
    name = jsonb_build_object('ca', t.prod_ca, 'es', t.prod_es),
    price = t.price_cents,
    vat_rate = 10,
    allergens = t.allergens,
    sold_out = t.sold_out,
    active = true,
    sort = t.prod_sort,
    updated_at = NOW()
FROM tmp_carta t
JOIN categories c ON c.deleted_at IS NULL AND c.name->>'ca' = t.cat_ca
WHERE p.deleted_at IS NULL
  AND p.category_id = c.id
  AND p.name->>'ca' = t.prod_ca;

-- Crear los que faltan
INSERT INTO products (
    category_id, name, price, vat_rate, allergens, active, sold_out, sort, order_count, created_at, updated_at
)
SELECT
    c.id,
    jsonb_build_object('ca', t.prod_ca, 'es', t.prod_es),
    t.price_cents,
    10,
    t.allergens,
    true,
    t.sold_out,
    t.prod_sort,
    0,
    NOW(),
    NOW()
FROM tmp_carta t
JOIN categories c ON c.deleted_at IS NULL AND c.name->>'ca' = t.cat_ca
WHERE NOT EXISTS (
    SELECT 1 FROM products p
    WHERE p.deleted_at IS NULL
      AND p.category_id = c.id
      AND p.name->>'ca' = t.prod_ca
);

DROP TABLE tmp_carta;

COMMIT;

-- Comprobación (opcional: ejecútalo aparte si quieres ver el resultado)
-- SELECT c.name->>'ca' AS categoria, p.name->>'ca' AS producte, p.price / 100.0 AS euros, p.sold_out
-- FROM products p
-- JOIN categories c ON c.id = p.category_id
-- WHERE c.name->>'ca' IN ('Cafès','Infusions','Begudes','Cerveses','Vins i vermut','Licors i combinats','Esmorzars','Per picar','Entrepans')
--   AND p.deleted_at IS NULL
-- ORDER BY c.sort, p.sort;
