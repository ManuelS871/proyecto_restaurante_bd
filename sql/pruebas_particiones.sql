-- ============================================================
-- SCRIPT DE PRUEBAS DE PARTICIONAMIENTO POR FECHAS
-- Demuestra enrutamiento automático y Partition Pruning
-- ============================================================

\echo '========================================================='
\echo '1. INSERTANDO DATOS DE PRUEBA EN CADA RANGO DE FECHAS'
\echo '========================================================='

DO $$
DECLARE
    v_res_octubre INT;
    v_res_diciembre INT;
    v_res_2027 INT;
BEGIN
    -- Crear reservas para cada fecha si no existen
    INSERT INTO reservaciones (cliente_id, cantidad, estado) VALUES (1, 2, 0) RETURNING id INTO v_res_octubre;
    INSERT INTO reservaciones (cliente_id, cantidad, estado) VALUES (1, 4, 0) RETURNING id INTO v_res_diciembre;
    INSERT INTO reservaciones (cliente_id, cantidad, estado) VALUES (1, 2, 0) RETURNING id INTO v_res_2027;

    -- 1. Reserva en horarios_actual (Octubre 2026 -> ts_reservas_2)
    IF NOT EXISTS (SELECT 1 FROM horarios WHERE inicio = '2026-10-26 14:00:00') THEN
        INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion)
        VALUES (2, v_res_octubre, '2026-10-26 14:00:00', INTERVAL '90 minutes');
    END IF;

    -- 2. Reserva en horarios_futuro (Diciembre 2026 -> ts_reservas_3)
    IF NOT EXISTS (SELECT 1 FROM horarios WHERE inicio = '2026-12-24 20:00:00') THEN
        INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion)
        VALUES (3, v_res_diciembre, '2026-12-24 20:00:00', INTERVAL '120 minutes');
    END IF;

    -- 3. Reserva en horarios_default (Año 2027 -> pg_default)
    IF NOT EXISTS (SELECT 1 FROM horarios WHERE inicio = '2027-02-14 19:00:00') THEN
        INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion)
        VALUES (4, v_res_2027, '2027-02-14 19:00:00', INTERVAL '90 minutes');
    END IF;
END $$;

\echo ''
\echo '========================================================='
\echo '2. VERIFICACIÓN DEL ENRUTAMIENTO AUTOMÁTICO A PARTICIONES'
\echo '========================================================='
SELECT 
    h.id,
    h.mesa_id,
    h.inicio,
    h.duracion,
    h.tableoid::regclass AS particion_destino,
    COALESCE(t.spcname, 'pg_default') AS tablespace_asignado
FROM horarios h
JOIN pg_class c ON h.tableoid = c.oid
LEFT JOIN pg_tablespace t ON c.reltablespace = t.oid
ORDER BY h.inicio;

\echo ''
\echo '========================================================='
\echo '3. DEMOSTRACIÓN DE PARTITION PRUNING (EXPLAIN PLAN)'
\echo '========================================================='
\echo 'Plan de ejecución buscando una reserva en Octubre 2026 (solo escanea horarios_actual):'
EXPLAIN (COSTS OFF)
SELECT * 
FROM horarios 
WHERE inicio BETWEEN '2026-10-26 00:00:00' AND '2026-10-26 23:59:59';

\echo ''
\echo 'Plan de ejecución buscando una reserva en Diciembre 2026 (solo escanea horarios_futuro):'
EXPLAIN (COSTS OFF)
SELECT * 
FROM horarios 
WHERE inicio BETWEEN '2026-12-01 00:00:00' AND '2026-12-31 23:59:59';

\echo ''
\echo 'Plan de ejecución buscando una reserva en 2027 (solo escanea horarios_default):'
EXPLAIN (COSTS OFF)
SELECT * 
FROM horarios 
WHERE inicio >= '2027-01-01 00:00:00';
