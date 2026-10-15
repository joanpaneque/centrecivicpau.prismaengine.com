-- =====================================================================================
-- Carta: opciones del asistente, cervezas/panaché y colores de familia (PostgreSQL)
--
-- Hecho para la carta que ya hay en producción (9 familias, ids 1-9, con precios y ventas):
-- se actualiza en el sitio, sin borrar nada, conservando ids, precios e historial.
-- * Familias: nombre ca/es, color de relleno y orden. Destinos sin tocar (impresoras en espera).
-- * Cervezas: "Canya mitjana" pasa a "Canya"; se añaden los 3 panachés SIN PRECIO.
-- * Grupos de opciones (leche, licor del carajillo, azúcar, marca) a 0 €.
-- Se puede ejecutar más de una vez: no duplica nada. SQL simple, apto para Adminer.
-- =====================================================================================

BEGIN;

-- 1. Familias: nombre en los dos idiomas, color y orden ------------------------------------
UPDATE categories AS c
SET name = v.name::json, color = v.color, sort = v.sort, updated_at = now()
FROM (VALUES
    (6, '{"ca":"Cafès","es":"Cafés"}',                              '#6b3f1d', 1),
    (7, '{"ca":"Infusions","es":"Infusiones"}',                     '#15803d', 2),
    (5, '{"ca":"Cerveses","es":"Cervezas"}',                        '#f59e0b', 3),
    (3, '{"ca":"Begudes","es":"Bebidas"}',                          '#0284c7', 4),
    (8, '{"ca":"Vins i vermut","es":"Vinos y vermut"}',             '#9f1239', 5),
    (2, '{"ca":"Licors i combinats","es":"Licores y combinados"}',  '#7c3aed', 6),
    (1, '{"ca":"Esmorzars","es":"Desayunos"}',                      '#ea580c', 7),
    (4, '{"ca":"Per picar","es":"Para picar"}',                     '#0f766e', 8),
    (9, '{"ca":"Entrepans","es":"Bocadillos"}',                     '#a16207', 9)
) AS v(id, name, color, sort)
WHERE c.id = v.id;

-- 2. Nombres corregidos (mantienen precio e historial) ---------------------------------------
UPDATE products AS p
SET name = v.name::json, updated_at = now()
FROM (VALUES
    (6, 'Capuccino',     '{"ca":"Caputxino","es":"Capuchino"}'),
    (5, 'Canya petita',  '{"ca":"Canya petita","es":"Caña pequeña"}'),
    (5, 'Canya mitjana', '{"ca":"Canya","es":"Caña"}'),
    (5, 'Gerra',         '{"ca":"Gerra","es":"Jarra"}')
) AS v(category_id, old_ca, name)
WHERE p.category_id = v.category_id AND p.name->>'ca' = v.old_ca AND p.deleted_at IS NULL;

-- 3. Panachés nuevos, sin precio (se pone durante el servicio) -------------------------------
INSERT INTO products (category_id, name, price, vat_rate, allergens, active, sold_out, sort, order_count, created_at, updated_at)
SELECT 5, v.name::json, NULL, 10, '[]'::json, true, false, v.sort, 0, now(), now()
FROM (VALUES
    ('Panaché petit',    '{"ca":"Panaché petit","es":"Panaché pequeño"}',     4),
    ('Panaché',          '{"ca":"Panaché","es":"Panaché"}',                   5),
    ('Gerra de panaché', '{"ca":"Gerra de panaché","es":"Jarra de panaché"}', 6)
) AS v(ca, name, sort)
WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = 5 AND p.name->>'ca' = v.ca AND p.deleted_at IS NULL);

-- Orden de Cerveses: caña pequeña, caña, jarra, panachés, clara y botellas
UPDATE products AS p
SET sort = v.sort, updated_at = now()
FROM (VALUES
    ('Canya petita', 1), ('Canya', 2), ('Gerra', 3), ('Panaché petit', 4), ('Panaché', 5), ('Gerra de panaché', 6),
    ('Clara', 7), ('Quinto', 8), ('Mitjana', 9), ('Cervesa sense alcohol', 10)
) AS v(ca, sort)
WHERE p.category_id = 5 AND p.name->>'ca' = v.ca AND p.deleted_at IS NULL;

-- 4. Grupos de opciones (una sola elección, no obligatorios). El orden = pasos del asistente --
WITH g AS (
    INSERT INTO modifier_groups (name, multiple, required, sort, created_at, updated_at)
    SELECT '{"ca":"Llet","es":"Leche"}'::json, false, false, 1, now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM modifier_groups WHERE name->>'ca' = 'Llet' AND deleted_at IS NULL)
    RETURNING id
)
INSERT INTO modifiers (modifier_group_id, name, price_delta, sort, created_at, updated_at)
SELECT g.id, v.name::json, 0, v.sort, now(), now()
FROM g, (VALUES
    ('{"ca":"Sense lactosa","es":"Sin lactosa"}', 1),
    ('{"ca":"De civada","es":"De avena"}', 2)
) AS v(name, sort);

WITH g AS (
    INSERT INTO modifier_groups (name, multiple, required, sort, created_at, updated_at)
    SELECT '{"ca":"Licor del carajillo","es":"Licor del carajillo"}'::json, false, false, 2, now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM modifier_groups WHERE name->>'ca' = 'Licor del carajillo' AND deleted_at IS NULL)
    RETURNING id
)
INSERT INTO modifiers (modifier_group_id, name, price_delta, sort, created_at, updated_at)
SELECT g.id, v.name::json, 0, v.sort, now(), now()
FROM g, (VALUES
    ('{"ca":"Conyac","es":"Coñac"}', 1),
    ('{"ca":"Whisky","es":"Whisky"}', 2),
    ('{"ca":"Rom","es":"Ron"}', 3),
    ('{"ca":"Anís","es":"Anís"}', 4),
    ('{"ca":"Baileys","es":"Baileys"}', 5)
) AS v(name, sort);

WITH g AS (
    INSERT INTO modifier_groups (name, multiple, required, sort, created_at, updated_at)
    SELECT '{"ca":"Sucre","es":"Azúcar"}'::json, false, false, 3, now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM modifier_groups WHERE name->>'ca' = 'Sucre' AND deleted_at IS NULL)
    RETURNING id
)
INSERT INTO modifiers (modifier_group_id, name, price_delta, sort, created_at, updated_at)
SELECT g.id, v.name::json, 0, v.sort, now(), now()
FROM g, (VALUES
    ('{"ca":"Sucre","es":"Azúcar"}', 1),
    ('{"ca":"Sucre moreno","es":"Azúcar moreno"}', 2),
    ('{"ca":"Sacarina","es":"Sacarina"}', 3)
) AS v(name, sort);

WITH g AS (
    INSERT INTO modifier_groups (name, multiple, required, sort, created_at, updated_at)
    SELECT '{"ca":"Marca","es":"Marca"}'::json, false, false, 4, now(), now()
    WHERE NOT EXISTS (SELECT 1 FROM modifier_groups WHERE name->>'ca' = 'Marca' AND deleted_at IS NULL)
    RETURNING id
)
INSERT INTO modifiers (modifier_group_id, name, price_delta, sort, created_at, updated_at)
SELECT g.id, v.name::json, 0, v.sort, now(), now()
FROM g, (VALUES
    ('{"ca":"Estrella","es":"Estrella"}', 1),
    ('{"ca":"San Miguel","es":"San Miguel"}', 2),
    ('{"ca":"Sense alcohol","es":"Sin alcohol"}', 3),
    ('{"ca":"Sense alcohol torrada","es":"Sin alcohol tostada"}', 4)
) AS v(name, sort);

-- 5. Qué productos abren cada grupo ----------------------------------------------------------
INSERT INTO modifier_group_product (modifier_group_id, product_id)
SELECT g.id, p.id
FROM (VALUES
    ('Llet',                6, 'Tallat'),
    ('Llet',                6, 'Cafè amb llet'),
    ('Licor del carajillo', 6, 'Carajillo'),
    ('Licor del carajillo', 6, 'Carajillo superior'),
    ('Sucre',               6, 'Cafè sol'),
    ('Sucre',               6, 'Tallat'),
    ('Sucre',               6, 'Cafè amb llet'),
    ('Sucre',               6, 'Cafè doble'),
    ('Sucre',               6, 'Caputxino'),
    ('Sucre',               6, 'Carajillo'),
    ('Sucre',               6, 'Carajillo superior'),
    ('Marca',               5, 'Canya petita'),
    ('Marca',               5, 'Canya'),
    ('Marca',               5, 'Gerra'),
    ('Marca',               5, 'Panaché petit'),
    ('Marca',               5, 'Panaché'),
    ('Marca',               5, 'Gerra de panaché')
) AS v(grp, category_id, product)
JOIN modifier_groups g ON g.name->>'ca' = v.grp AND g.deleted_at IS NULL
JOIN products p ON p.category_id = v.category_id AND p.name->>'ca' = v.product AND p.deleted_at IS NULL
ON CONFLICT DO NOTHING;

-- 6. Que las tablets y la caja lo descarguen en la próxima sincronización ---------------------
UPDATE products SET updated_at = now() WHERE deleted_at IS NULL;
UPDATE categories SET updated_at = now() WHERE deleted_at IS NULL;

-- Comprobación: debe salir cada grupo con sus opciones y productos
SELECT g.name->>'ca' AS grup,
       (SELECT string_agg(m.name->>'ca', ', ' ORDER BY m.sort) FROM modifiers m WHERE m.modifier_group_id = g.id) AS opcions,
       (SELECT string_agg(p.name->>'ca', ', ' ORDER BY p.sort) FROM modifier_group_product x JOIN products p ON p.id = x.product_id WHERE x.modifier_group_id = g.id) AS productes
FROM modifier_groups g WHERE g.deleted_at IS NULL ORDER BY g.sort;

COMMIT;
