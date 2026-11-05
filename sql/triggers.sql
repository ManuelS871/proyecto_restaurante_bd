-- ============================================================
-- PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
-- TRIGGERS DE VALIDACIÓN DE INTEGRIDAD Y REGLAS DE NEGOCIO
-- ============================================================

-- ------------------------------------------------------------
-- 1. TRIGGER: VALIDAR ROL DE MESERO EN PEDIDOS
-- Regla ER: "mesero_id REFERENCES usuarios(id), el usuario DEBE tener el rol de mesero"
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_validar_mesero_pedido()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM actuaciones a
        JOIN roles r ON a.rol_id = r.id
        WHERE a.usuario_id = NEW.mesero_id
          AND LOWER(r.nombre) = 'mesero'
    ) THEN
        RAISE EXCEPTION 'Violación de regla de negocio: El usuario con ID % no tiene asignado el rol de mesero.', NEW.mesero_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_mesero_pedido ON pedidos;
CREATE TRIGGER trg_validar_mesero_pedido
BEFORE INSERT OR UPDATE OF mesero_id ON pedidos
FOR EACH ROW
EXECUTE FUNCTION fn_validar_mesero_pedido();


-- ------------------------------------------------------------
-- 2. TRIGGER: VALIDAR ROL DE COCINERO EN ESPECIALIDADES
-- Regla ER: "cocinero_id REFERENCES usuarios(id), el usuario DEBE tener rol de cocinero"
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_validar_cocinero_especialidad()
RETURNS TRIGGER AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM actuaciones a
        JOIN roles r ON a.rol_id = r.id
        WHERE a.usuario_id = NEW.cocinero_id
          AND LOWER(r.nombre) = 'cocinero'
    ) THEN
        RAISE EXCEPTION 'Violación de regla de negocio: El usuario con ID % no tiene asignado el rol de cocinero.', NEW.cocinero_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_cocinero_especialidad ON especialidades;
CREATE TRIGGER trg_validar_cocinero_especialidad
BEFORE INSERT OR UPDATE OF cocinero_id ON especialidades
FOR EACH ROW
EXECUTE FUNCTION fn_validar_cocinero_especialidad();


-- ------------------------------------------------------------
-- 3. TRIGGER: VALIDAR ROL Y ESPECIALIDAD DE COCINERO EN PREPARACIONES
-- Regla ER: "cocinero_id REFERENCES usuarios(id), el usuario DEBE tener rol de cocinero y tener la especialidad en el plato"
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_validar_preparacion_cocinero()
RETURNS TRIGGER AS $$
DECLARE
    v_plato_id INT;
BEGIN
    -- Validar que el usuario tenga rol de cocinero
    IF NOT EXISTS (
        SELECT 1
        FROM actuaciones a
        JOIN roles r ON a.rol_id = r.id
        WHERE a.usuario_id = NEW.cocinero_id
          AND LOWER(r.nombre) = 'cocinero'
    ) THEN
        RAISE EXCEPTION 'Violación de regla de negocio: El usuario con ID % no tiene el rol de cocinero.', NEW.cocinero_id;
    END IF;

    -- Obtener el plato correspondiente a la orden solicitada
    SELECT plato_id INTO v_plato_id
    FROM ordenes
    WHERE id = NEW.orden_id;

    IF v_plato_id IS NULL THEN
        RAISE EXCEPTION 'Violación de regla de negocio: La orden con ID % no existe.', NEW.orden_id;
    END IF;

    -- Validar que el cocinero tenga registrada la especialidad de ese plato
    IF NOT EXISTS (
        SELECT 1
        FROM especialidades e
        WHERE e.cocinero_id = NEW.cocinero_id
          AND e.plato_id = v_plato_id
    ) THEN
        RAISE EXCEPTION 'Violación de regla de negocio: El cocinero con ID % no tiene la especialidad para preparar el plato (ID %) de la orden %.',
            NEW.cocinero_id, v_plato_id, NEW.orden_id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_preparacion_cocinero ON preparaciones;
CREATE TRIGGER trg_validar_preparacion_cocinero
BEFORE INSERT OR UPDATE OF cocinero_id, orden_id ON preparaciones
FOR EACH ROW
EXECUTE FUNCTION fn_validar_preparacion_cocinero();


-- ------------------------------------------------------------
-- 4. TRIGGER: VALIDAR RESERVAS Y HORARIOS (NO CRUCE Y AFORO DE MESA Y RESTAURANTE)
-- Regla Enunciado:
-- - No se deben cruzar las reservaciones en la misma mesa.
-- - No superar el cupo total del restaurante en ningún horario.
-- - No superar la capacidad de sillas de la mesa asignada.
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_validar_horario_reserva()
RETURNS TRIGGER AS $$
DECLARE
    v_sillas INT;
    v_cantidad INT;
    v_cupo_total INT;
    v_ocupacion_actual INT;
BEGIN
    -- 1. Validar que la duración sea coherente (máximo 2 horas según enunciado)
    IF NEW.duracion <= INTERVAL '0 seconds' OR NEW.duracion > INTERVAL '2 hours' THEN
        RAISE EXCEPTION 'Violación de regla de negocio: La duración de la reserva debe ser mayor a 0 y no mayor a 2 horas.';
    END IF;

    -- 2. Obtener capacidad de la mesa y cantidad de personas de la reserva
    SELECT sillas INTO v_sillas FROM mesas WHERE id = NEW.mesa_id;
    IF v_sillas IS NULL THEN
        RAISE EXCEPTION 'Violación de regla de negocio: La mesa % no existe.', NEW.mesa_id;
    END IF;

    SELECT cantidad INTO v_cantidad FROM reservaciones WHERE id = NEW.reservacion_id;
    IF v_cantidad IS NULL THEN
        RAISE EXCEPTION 'Violación de regla de negocio: La reservación % no existe.', NEW.reservacion_id;
    END IF;

    -- Validar que la cantidad de personas quepa en la mesa asignada
    IF v_cantidad > v_sillas THEN
        RAISE EXCEPTION 'Violación de regla de negocio: La reserva % requiere % asientos, pero la mesa % solo tiene capacidad para % sillas.',
            NEW.reservacion_id, v_cantidad, NEW.mesa_id, v_sillas;
    END IF;

    -- 3. Validar que NO se cruce con otra reserva en la misma mesa (excluyendo canceladas: estado = 3)
    IF EXISTS (
        SELECT 1
        FROM horarios h
        JOIN reservaciones r ON h.reservacion_id = r.id
        WHERE h.mesa_id = NEW.mesa_id
          AND (NEW.id IS NULL OR h.id <> NEW.id)
          AND r.estado <> 3 -- No cancelada
          AND tsrange(h.inicio, h.inicio + h.duracion) && tsrange(NEW.inicio, NEW.inicio + NEW.duracion)
    ) THEN
        RAISE EXCEPTION 'Conflicto de reserva: La mesa % ya se encuentra reservada en el rango horario solicitado (% a %).',
            NEW.mesa_id, NEW.inicio, NEW.inicio + NEW.duracion;
    END IF;

    -- 4. Validar que no se supere el aforo total del restaurante sumando todas las reservas simultáneas
    SELECT COALESCE(SUM(sillas), 0) INTO v_cupo_total FROM mesas;

    SELECT COALESCE(SUM(r.cantidad), 0) INTO v_ocupacion_actual
    FROM horarios h
    JOIN reservaciones r ON h.reservacion_id = r.id
    WHERE (NEW.id IS NULL OR h.id <> NEW.id)
      AND r.estado <> 3 -- No cancelada
      AND tsrange(h.inicio, h.inicio + h.duracion) && tsrange(NEW.inicio, NEW.inicio + NEW.duracion);

    IF (v_ocupacion_actual + v_cantidad) > v_cupo_total THEN
        RAISE EXCEPTION 'Aforo total superado: La ocupación proyectada (% personas) excede el cupo total del restaurante (% personas).',
            (v_ocupacion_actual + v_cantidad), v_cupo_total;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_horario_reserva ON horarios;
CREATE TRIGGER trg_validar_horario_reserva
BEFORE INSERT OR UPDATE OF mesa_id, reservacion_id, inicio, duracion ON horarios
FOR EACH ROW
EXECUTE FUNCTION fn_validar_horario_reserva();


-- ------------------------------------------------------------
-- 5. TRIGGER: VALIDAR TRANSICIÓN DE ESTADOS DE ÓRDENES
-- Regla Enunciado:
-- 0 = solicitado, 1 = preparado, 2 = entregado
-- No se puede entregar sin estar preparado, ni revertir una orden entregada.
-- ------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_validar_transicion_orden()
RETURNS TRIGGER AS $$
BEGIN
    -- No permitir saltar de solicitado (0) a entregado (2) sin haber sido preparado (1)
    IF OLD.estado = 0 AND NEW.estado = 2 THEN
        RAISE EXCEPTION 'Transición de estado inválida: La orden % no puede entregarse sin haber sido preparada previamente.', NEW.id;
    END IF;

    -- No permitir revertir una orden entregada (2) a estados previos
    IF OLD.estado = 2 AND NEW.estado < 2 THEN
        RAISE EXCEPTION 'Transición de estado inválida: La orden % ya fue entregada y no puede retroceder de estado.', NEW.id;
    END IF;

    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_validar_transicion_orden ON ordenes;
CREATE TRIGGER trg_validar_transicion_orden
BEFORE UPDATE OF estado ON ordenes
FOR EACH ROW
EXECUTE FUNCTION fn_validar_transicion_orden();
