BEGIN;


INSERT INTO tipos(nombre)
VALUES
('Entrada'),
('Plato fuerte'),
('Bebida'),
('Postre');

INSERT INTO roles(nombre)
VALUES
('cliente'),
('mesero'),
('cocinero'),
('administrador');

INSERT INTO usuarios(nombre, clave, fecha_clave)
VALUES
(
 'Carlos Perez',
 digest('cliente123','sha256'),
 NOW()
),
(
 'Laura Gomez',
 digest('mesero123','sha256'),
 NOW()
),
(
 'Juan Martinez',
 digest('cocinero123','sha256'),
 NOW()
),
(
 'Ana Rodriguez',
 digest('admin123','sha256'),
 NOW()
);


INSERT INTO actuaciones(rol_id,usuario_id)
VALUES
(
 (SELECT id FROM roles WHERE nombre='cliente'),
 (SELECT id FROM usuarios WHERE nombre='Carlos Perez')
);


INSERT INTO actuaciones(rol_id,usuario_id)
VALUES
(
 (SELECT id FROM roles WHERE nombre='mesero'),
 (SELECT id FROM usuarios WHERE nombre='Laura Gomez')
);


INSERT INTO actuaciones(rol_id,usuario_id)
VALUES
(
 (SELECT id FROM roles WHERE nombre='cocinero'),
 (SELECT id FROM usuarios WHERE nombre='Juan Martinez')
);


INSERT INTO actuaciones(rol_id,usuario_id)
VALUES
(
 (SELECT id FROM roles WHERE nombre='administrador'),
 (SELECT id FROM usuarios WHERE nombre='Ana Rodriguez')
);


INSERT INTO platos(
tipo_id,
nombre,
descripcion,
tiempo,
precio
)
VALUES

(
 (SELECT id FROM tipos WHERE nombre='Entrada'),
 'Bruschetta',
 'Pan tostado con tomate y especias',
 INTERVAL '15 minutes',
 12000
),

(
 (SELECT id FROM tipos WHERE nombre='Plato fuerte'),
 'Filete de carne',
 'Carne premium con acompañamiento',
 INTERVAL '45 minutes',
 35000
),

(
 (SELECT id FROM tipos WHERE nombre='Plato fuerte'),
 'Pasta italiana',
 'Pasta artesanal con salsa especial',
 INTERVAL '30 minutes',
 25000
),

(
 (SELECT id FROM tipos WHERE nombre='Bebida'),
 'Limonada natural',
 'Limonada preparada en casa',
 INTERVAL '5 minutes',
 8000
),

(
 (SELECT id FROM tipos WHERE nombre='Postre'),
 'Cheesecake',
 'Postre de queso con frutos rojos',
 INTERVAL '20 minutes',
 15000
);


INSERT INTO especialidades(
cocinero_id,
plato_id
)
VALUES

(
 (SELECT id FROM usuarios WHERE nombre='Juan Martinez'),
 (SELECT id FROM platos WHERE nombre='Filete de carne')
),

(
 (SELECT id FROM usuarios WHERE nombre='Juan Martinez'),
 (SELECT id FROM platos WHERE nombre='Pasta italiana')
),

(
 (SELECT id FROM usuarios WHERE nombre='Juan Martinez'),
 (SELECT id FROM platos WHERE nombre='Cheesecake')
);


INSERT INTO mesas(sillas)
VALUES
(2),
(4),
(6),
(8);



-- =====================================
-- RESERVACIONES
-- estado:
-- 0 reservada
-- 1 ocupada
-- 2 liberada
-- 3 cancelada
-- =====================================

INSERT INTO reservaciones(
cliente_id,
cantidad,
estado
)
VALUES

(
(SELECT id FROM usuarios WHERE nombre='Carlos Perez'),
2,
0
);


INSERT INTO pedidos(
cliente_id,
mesero_id
)
VALUES

(
(SELECT id FROM usuarios WHERE nombre='Carlos Perez'),

(SELECT id FROM usuarios WHERE nombre='Laura Gomez')
);



-- =====================================
-- ORDENES
--
-- estado:
-- 0 solicitado
-- 1 preparado
-- 2 entregado
-- =====================================

INSERT INTO ordenes(
plato_id,
pedido_id,
estado,
cantidad,
solicitado
)
VALUES

(
(SELECT id FROM platos WHERE nombre='Filete de carne'),
1,
0,
1,
NOW()
),

(
(SELECT id FROM platos WHERE nombre='Limonada natural'),
1,
0,
2,
NOW()
);


INSERT INTO preparaciones(
cocinero_id,
orden_id
)
VALUES

(
(SELECT id FROM usuarios WHERE nombre='Juan Martinez'),
1
);


INSERT INTO horarios(
mesa_id,
reservacion_id,
inicio,
duracion
)
VALUES

(
1,
1,
'2026-08-26 19:00:00',
INTERVAL '90 minutes'
);


COMMIT;
