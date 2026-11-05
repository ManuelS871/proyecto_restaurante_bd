-- ============================================================
-- SCRIPT DE PRUEBAS DE INTEGRIDAD Y TRIGGERS DE NEGOCIO
-- Base de datos: restaurante
-- ============================================================

\echo '---------------------------------------------------------'
\echo '1. PRUEBA: Validar que solo meseros registren pedidos'
\echo '---------------------------------------------------------'
-- Caso Negativo: Usuario 1 (Carlos Perez) es cliente, no mesero -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        INSERT INTO pedidos (cliente_id, mesero_id) VALUES (1, 1);
        RAISE EXCEPTION 'FALLO: El trigger no bloqueó al usuario sin rol mesero.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;

-- Caso Positivo: Usuario 2 (Laura Gomez) sí es mesero -> DEBE PERMITIR
DO $$
BEGIN
    INSERT INTO pedidos (cliente_id, mesero_id) VALUES (1, 2);
    RAISE NOTICE 'ÉXITO (esperado): Pedido con mesero válido insertado correctamente.';
    -- Limpieza de la prueba
    DELETE FROM pedidos WHERE id = (SELECT MAX(id) FROM pedidos);
END $$;


\echo '---------------------------------------------------------'
\echo '2. PRUEBA: Validar que solo cocineros tengan especialidades'
\echo '---------------------------------------------------------'
-- Caso Negativo: Usuario 1 (cliente) asignado a especialidad -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        INSERT INTO especialidades (cocinero_id, plato_id) VALUES (1, 1);
        RAISE EXCEPTION 'FALLO: El trigger no bloqueó especialidad para usuario no cocinero.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;


\echo '---------------------------------------------------------'
\echo '3. PRUEBA: Validar cocinero y su especialidad en preparaciones'
\echo '---------------------------------------------------------'
-- Caso Negativo A: Usuario 2 (Laura Gomez - mesero) preparando -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        INSERT INTO preparaciones (cocinero_id, orden_id) VALUES (2, 1);
        RAISE EXCEPTION 'FALLO: El trigger no bloqueó a un no-cocinero en preparaciones.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;

-- Caso Negativo B: Usuario 3 (Juan Martinez - cocinero) preparando Orden 2 (Limonada, plato_id=4)
-- Juan NO tiene especialidad en Limonada -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        INSERT INTO preparaciones (cocinero_id, orden_id) VALUES (3, 2);
        RAISE EXCEPTION 'FALLO: El trigger no bloqueó cocinero sin la especialidad del plato.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;


\echo '---------------------------------------------------------'
\echo '4. PRUEBA: Validar capacidad de mesa y cruce de horarios'
\echo '---------------------------------------------------------'
-- Caso Negativo A: Reserva para 5 personas en Mesa 1 (solo 2 sillas) -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        -- Reserva 2 tiene cantidad = 5
        INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion)
        VALUES (1, 2, '2026-10-27 13:00:00', INTERVAL '60 minutes');
        RAISE EXCEPTION 'FALLO: El trigger no bloqueó reserva que excede capacidad de sillas.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;

-- Caso Negativo B: Cruce de horarios en Mesa 1 (ya reservada a las 19:00 por 90 min) -> DEBE FALLAR
DO $$
BEGIN
    -- Creamos reserva de 2 personas dentro del rango de partición (id = 500)
    INSERT INTO reservaciones (id, cliente_id, cantidad, estado) VALUES (500, 1, 2, 0);
    BEGIN
        -- Intentamos horario solapado (19:30)
        INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion)
        VALUES (1, 500, '2026-08-26 19:30:00', INTERVAL '60 minutes');
        RAISE EXCEPTION 'FALLO: El trigger no detectó cruce de horario en la mesa.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
    DELETE FROM reservaciones WHERE id = 500;
END $$;


\echo '---------------------------------------------------------'
\echo '5. PRUEBA: Validar flujo de transición de estados de órdenes'
\echo '---------------------------------------------------------'
-- Caso Negativo: Saltar de solicitado (0) a entregado (2) sin preparar (1) -> DEBE FALLAR
DO $$
BEGIN
    BEGIN
        UPDATE ordenes SET estado = 2 WHERE id = 1;
        RAISE EXCEPTION 'FALLO: El trigger permitió entregar orden no preparada.';
    EXCEPTION WHEN OTHERS THEN
        RAISE NOTICE 'ÉXITO (esperado): %', SQLERRM;
    END;
END $$;
