-- ============================================================
-- PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
-- SCRIPT DE PARTICIONAMIENTO POR FECHAS SOBRE TABLESPACES
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- PASO 1: RESPALDAR DATOS EXISTENTES
-- ------------------------------------------------------------
CREATE TEMP TABLE tmp_reservaciones AS 
SELECT id, cliente_id, cantidad, estado 
FROM reservaciones;

CREATE TEMP TABLE tmp_horarios AS 
SELECT id, mesa_id, reservacion_id, inicio, duracion 
FROM horarios;

-- ------------------------------------------------------------
-- PASO 2: ELIMINAR TABLAS CON PARTICIONADO OBSOLETO POR ID
-- ------------------------------------------------------------
DROP TABLE IF EXISTS horarios CASCADE;
DROP TABLE IF EXISTS reservaciones CASCADE;
DROP TABLE IF EXISTS reservaciones_1 CASCADE;
DROP TABLE IF EXISTS reservaciones_2 CASCADE;
DROP TABLE IF EXISTS reservaciones_3 CASCADE;
DROP TABLE IF EXISTS reservaciones_backup CASCADE;

-- ------------------------------------------------------------
-- PASO 3: RECREAR RESERVACIONES COMO TABLA ESTÁNDAR (SEGÚN DIAGRAMA ER)
-- ------------------------------------------------------------
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

-- Restaurar datos de reservaciones
INSERT INTO reservaciones (id, cliente_id, cantidad, estado)
SELECT id, cliente_id, cantidad, estado FROM tmp_reservaciones;

-- Sincronizar secuencia de reservaciones
SELECT setval('reservaciones_id_seq', (SELECT COALESCE(MAX(id), 1) FROM reservaciones), true);


-- ------------------------------------------------------------
-- PASO 4: CREAR TABLA HORARIOS PARTICIONADA POR FECHAS (RANGE INICIO)
-- Requisito Técnico 6.1: "Particionar una tabla por fechas para que use los tablespaces definidos."
-- ------------------------------------------------------------
CREATE TABLE horarios (
    id SERIAL,
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
            AND duracion <= INTERVAL '2 hours'
        ),

    PRIMARY KEY (id, inicio)
) PARTITION BY RANGE (inicio);


-- ------------------------------------------------------------
-- PASO 5: ASIGNAR PARTICIONES TEMPORALES A LOS TABLESPACES FÍSICOS
-- ------------------------------------------------------------

-- Partición 1 (Histórico: Reservas anteriores a Septiembre 2026) en tablespace 1
CREATE TABLE horarios_historico PARTITION OF horarios
    FOR VALUES FROM (MINVALUE) TO ('2026-09-01 00:00:00')
    TABLESPACE ts_reservas_1;

-- Partición 2 (Actual: Septiembre y Octubre 2026) en tablespace 2
CREATE TABLE horarios_actual PARTITION OF horarios
    FOR VALUES FROM ('2026-09-01 00:00:00') TO ('2026-11-01 00:00:00')
    TABLESPACE ts_reservas_2;

-- Partición 3 (Futuro: Noviembre y Diciembre 2026) en tablespace 3
CREATE TABLE horarios_futuro PARTITION OF horarios
    FOR VALUES FROM ('2026-11-01 00:00:00') TO ('2027-01-01 00:00:00')
    TABLESPACE ts_reservas_3;

-- Partición por Defecto (Reservas de años posteriores) en tablespace por defecto
CREATE TABLE horarios_default PARTITION OF horarios
    DEFAULT TABLESPACE pg_default;


-- ------------------------------------------------------------
-- PASO 6: RESTAURAR DATOS EN HORARIOS
-- (PostgreSQL enrutará automáticamente la fila a horarios_historico)
-- ------------------------------------------------------------
INSERT INTO horarios (id, mesa_id, reservacion_id, inicio, duracion)
SELECT id, mesa_id, reservacion_id, inicio, duracion FROM tmp_horarios;

-- Sincronizar secuencia de horarios
SELECT setval('horarios_id_seq', (SELECT COALESCE(MAX(id), 1) FROM horarios), true);


-- ------------------------------------------------------------
-- PASO 7: REASOCIAR EL TRIGGER DE NEGOCIO A HORARIOS
-- ------------------------------------------------------------
DROP TRIGGER IF EXISTS trg_validar_horario_reserva ON horarios;
CREATE TRIGGER trg_validar_horario_reserva
BEFORE INSERT OR UPDATE OF mesa_id, reservacion_id, inicio, duracion ON horarios
FOR EACH ROW
EXECUTE FUNCTION fn_validar_horario_reserva();

COMMIT;
