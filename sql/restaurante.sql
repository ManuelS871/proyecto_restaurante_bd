
BEGIN;

CREATE TABLE usuarios (
    id SERIAL PRIMARY KEY,
    nombre TEXT NOT NULL,
    clave BYTEA NOT NULL,
    fecha_clave TIMESTAMP NOT NULL
);


CREATE TABLE roles (
    id SERIAL PRIMARY KEY,
    nombre TEXT NOT NULL
);


CREATE TABLE tipos (
    id SERIAL PRIMARY KEY,
    nombre TEXT NOT NULL
);


CREATE TABLE mesas (
    id SERIAL PRIMARY KEY,
    sillas INT NOT NULL,

    CONSTRAINT chk_mesas_sillas
        CHECK (sillas > 0)
);


CREATE TABLE platos (
    id SERIAL PRIMARY KEY,
    tipo_id INT NOT NULL,
    nombre TEXT NOT NULL,
    descripcion TEXT,
    tiempo INTERVAL NOT NULL,
    precio NUMERIC NOT NULL,

    CONSTRAINT fk_platos_tipos
        FOREIGN KEY (tipo_id)
        REFERENCES tipos(id),

    CONSTRAINT chk_platos_tiempo
        CHECK (tiempo > INTERVAL '0 seconds'),

    CONSTRAINT chk_platos_precio
        CHECK (precio >= 0)
);


CREATE TABLE actuaciones (
    id SERIAL PRIMARY KEY,
    rol_id INT NOT NULL,
    usuario_id INT NOT NULL,

    CONSTRAINT fk_actuaciones_roles
        FOREIGN KEY (rol_id)
        REFERENCES roles(id),

    CONSTRAINT fk_actuaciones_usuarios
        FOREIGN KEY (usuario_id)
        REFERENCES usuarios(id),

    CONSTRAINT uq_usuario_rol
        UNIQUE (usuario_id, rol_id)
);


-- ============================================================
-- RESERVACIONES
--
-- estado:
-- 0 = reservada
-- 1 = ocupada
-- 2 = liberada
-- 3 = cancelada
-- ============================================================

CREATE TABLE reservaciones (
    id SERIAL PRIMARY KEY,
    cliente_id INT NOT NULL,
    cantidad INT NOT NULL,
    estado INT NOT NULL,

    CONSTRAINT fk_reservaciones_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES usuarios(id),

    CONSTRAINT chk_reservaciones_cantidad
        CHECK (cantidad > 0),

    CONSTRAINT chk_reservaciones_estado
        CHECK (estado IN (0, 1, 2, 3))
);


CREATE TABLE pedidos (
    id SERIAL PRIMARY KEY,
    cliente_id INT NOT NULL,
    mesero_id INT NOT NULL,

    CONSTRAINT fk_pedidos_cliente
        FOREIGN KEY (cliente_id)
        REFERENCES usuarios(id),

    CONSTRAINT fk_pedidos_mesero
        FOREIGN KEY (mesero_id)
        REFERENCES usuarios(id)
);


-- ============================================================
-- ORDENES
--
-- estado:
-- 0 = solicitado
-- 1 = preparado
-- 2 = entregado
-- ============================================================

CREATE TABLE ordenes (
    id SERIAL PRIMARY KEY,
    plato_id INT NOT NULL,
    pedido_id INT NOT NULL,
    estado INT NOT NULL,
    cantidad INT NOT NULL,
    solicitado TIMESTAMP WITH TIME ZONE NOT NULL,

    CONSTRAINT fk_ordenes_plato
        FOREIGN KEY (plato_id)
        REFERENCES platos(id),

    CONSTRAINT fk_ordenes_pedido
        FOREIGN KEY (pedido_id)
        REFERENCES pedidos(id),

    CONSTRAINT chk_ordenes_estado
        CHECK (estado IN (0, 1, 2)),

    CONSTRAINT chk_ordenes_cantidad
        CHECK (cantidad > 0)
);


CREATE TABLE especialidades (
    id SERIAL PRIMARY KEY,
    cocinero_id INT NOT NULL,
    plato_id INT NOT NULL,

    CONSTRAINT fk_especialidades_cocinero
        FOREIGN KEY (cocinero_id)
        REFERENCES usuarios(id),

    CONSTRAINT fk_especialidades_plato
        FOREIGN KEY (plato_id)
        REFERENCES platos(id),

    CONSTRAINT uq_cocinero_plato
        UNIQUE (cocinero_id, plato_id)
);


CREATE TABLE preparaciones (
    id SERIAL PRIMARY KEY,
    cocinero_id INT NOT NULL,
    orden_id INT NOT NULL,

    CONSTRAINT fk_preparaciones_cocinero
        FOREIGN KEY (cocinero_id)
        REFERENCES usuarios(id),

    CONSTRAINT fk_preparaciones_orden
        FOREIGN KEY (orden_id)
        REFERENCES ordenes(id)
);


CREATE TABLE horarios (
    id SERIAL PRIMARY KEY,
    mesa_id INT NOT NULL,
    reservacion_id INT NOT NULL,
    inicio TIMESTAMP NOT NULL,
    duracion INTERVAL NOT NULL,

    CONSTRAINT fk_horarios_mesa
        FOREIGN KEY (mesa_id)
        REFERENCES mesas(id),

    CONSTRAINT fk_horarios_reservacion
        FOREIGN KEY (reservacion_id)
        REFERENCES reservaciones(id),

    CONSTRAINT chk_horarios_duracion
        CHECK (
            duracion > INTERVAL '0 seconds'
            AND duracion < INTERVAL '2 hours'
        )
);


COMMIT;
