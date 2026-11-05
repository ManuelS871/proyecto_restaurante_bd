-- ============================================================
-- PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
-- SCRIPT DE CARGA DE DATOS PARA REPORTES Y ESTADÍSTICAS
-- ============================================================

BEGIN;

-- 1. Insertar rol maitre si no existe
INSERT INTO roles (nombre)
SELECT 'maitre'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE LOWER(nombre) = 'maitre');

-- 2. Insertar usuarios adicionales para todos los roles
-- Contraseña genérica 'pass123' con digest sha256
INSERT INTO usuarios (nombre, clave, fecha_clave) VALUES
('Diego Ramirez', digest('maitre123', 'sha256'), NOW()),      -- Maitre (id ~ 5)
('Sofia Lopez', digest('mesero123', 'sha256'), NOW()),        -- Mesero (id ~ 6)
('Andres Torres', digest('cocinero123', 'sha256'), NOW()),    -- Cocinero (id ~ 7)
('Mariana Silva', digest('cliente123', 'sha256'), NOW()),     -- Cliente (id ~ 8)
('Jorge Castro', digest('cliente123', 'sha256'), NOW()),      -- Cliente (id ~ 9)
('Valentina Rojas', digest('cliente123', 'sha256'), NOW())   -- Cliente (id ~ 10)
ON CONFLICT DO NOTHING;

-- 3. Asignar roles en actuaciones
INSERT INTO actuaciones (rol_id, usuario_id)
SELECT r.id, u.id
FROM (VALUES 
    ('Diego Ramirez', 'maitre'),
    ('Sofia Lopez', 'mesero'),
    ('Andres Torres', 'cocinero'),
    ('Mariana Silva', 'cliente'),
    ('Jorge Castro', 'cliente'),
    ('Valentina Rojas', 'cliente')
) AS v(usuario_nombre, rol_nombre)
JOIN usuarios u ON u.nombre = v.usuario_nombre
JOIN roles r ON r.nombre = v.rol_nombre
ON CONFLICT (usuario_id, rol_id) DO NOTHING;

-- 4. Asignar especialidades al nuevo cocinero Andres Torres
-- Andres sabe preparar Bruschetta (1), Limonada natural (4) y Pasta italiana (3)
INSERT INTO especialidades (cocinero_id, plato_id)
SELECT u.id, p.id
FROM usuarios u
CROSS JOIN platos p
WHERE u.nombre = 'Andres Torres' AND p.nombre IN ('Bruschetta', 'Limonada natural', 'Pasta italiana')
ON CONFLICT (cocinero_id, plato_id) DO NOTHING;

-- 5. Crear pedidos históricos y recientes
-- Pedidos con meseros válidos (Laura Gomez id=2, Sofia Lopez)
INSERT INTO pedidos (cliente_id, mesero_id)
SELECT c.id, m.id
FROM (VALUES
    ('Mariana Silva', 'Laura Gomez'),
    ('Jorge Castro', 'Sofia Lopez'),
    ('Valentina Rojas', 'Laura Gomez'),
    ('Carlos Perez', 'Sofia Lopez'),
    ('Mariana Silva', 'Sofia Lopez'),
    ('Jorge Castro', 'Laura Gomez')
) AS v(cliente_nom, mesero_nom)
JOIN usuarios c ON c.nombre = v.cliente_nom
JOIN usuarios m ON m.nombre = v.mesero_nom;

-- 6. Insertar órdenes variadas con fechas en Agosto, Septiembre y Octubre 2026
-- Estados: 0=solicitado, 1=preparado, 2=entregado
INSERT INTO ordenes (plato_id, pedido_id, estado, cantidad, solicitado)
SELECT p.id, ped.id, ord.estado, ord.cantidad, ord.fecha::timestamp with time zone
FROM (VALUES
    -- Agosto 2026
    ('Filete de carne', 2, 2, 2, '2026-08-15 13:30:00-05'),
    ('Limonada natural', 2, 2, 2, '2026-08-15 13:35:00-05'),
    ('Cheesecake', 2, 2, 1, '2026-08-15 14:15:00-05'),
    ('Bruschetta', 3, 2, 3, '2026-08-20 20:00:00-05'),
    ('Pasta italiana', 3, 2, 2, '2026-08-20 20:15:00-05'),

    -- Septiembre 2026
    ('Filete de carne', 4, 2, 3, '2026-09-05 14:00:00-05'),
    ('Pasta italiana', 4, 2, 1, '2026-09-05 14:05:00-05'),
    ('Limonada natural', 4, 2, 4, '2026-09-05 14:00:00-05'),
    ('Cheesecake', 5, 2, 2, '2026-09-18 19:30:00-05'),
    ('Bruschetta', 5, 2, 2, '2026-09-18 19:15:00-05'),

    -- Octubre 2026 (Semana pasada y actual)
    ('Filete de carne', 6, 2, 2, '2026-10-12 13:00:00-05'),
    ('Pasta italiana', 6, 2, 2, '2026-10-12 13:10:00-05'),
    ('Bruschetta', 7, 2, 1, '2026-10-20 20:00:00-05'),
    ('Filete de carne', 7, 2, 2, '2026-10-20 20:15:00-05'),
    ('Limonada natural', 7, 2, 3, '2026-10-20 20:00:00-05'),

    -- Hoy (2026-10-26)
    -- Una orden lista para ser entregada (estado=1) para probar notificación
    ('Pasta italiana', 6, 1, 1, '2026-10-26 14:10:00-05'),
    -- Una orden solicitada pendiente por cocinar (estado=0) para comanda de cocinero
    ('Filete de carne', 7, 0, 1, '2026-10-26 14:20:00-05'),
    ('Limonada natural', 7, 0, 2, '2026-10-26 14:25:00-05')
) AS ord(plato_nom, pedido_id, estado, cantidad, fecha)
JOIN platos p ON p.nombre = ord.plato_nom
JOIN pedidos ped ON ped.id = ord.pedido_id;

-- 7. Registrar preparaciones válidas para las órdenes preparadas/entregadas
-- Cocinero Juan Martinez (sabe preparar Filete, Pasta, Cheesecake)
INSERT INTO preparaciones (cocinero_id, orden_id)
SELECT u.id, o.id
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN usuarios u ON u.nombre = 'Juan Martinez'
WHERE p.nombre IN ('Filete de carne', 'Pasta italiana', 'Cheesecake')
  AND o.estado IN (1, 2)
  AND NOT EXISTS (SELECT 1 FROM preparaciones pr WHERE pr.orden_id = o.id);

-- Cocinero Andres Torres (sabe preparar Bruschetta, Limonada natural)
INSERT INTO preparaciones (cocinero_id, orden_id)
SELECT u.id, o.id
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN usuarios u ON u.nombre = 'Andres Torres'
WHERE p.nombre IN ('Bruschetta', 'Limonada natural')
  AND o.estado IN (1, 2)
  AND NOT EXISTS (SELECT 1 FROM preparaciones pr WHERE pr.orden_id = o.id);

COMMIT;
