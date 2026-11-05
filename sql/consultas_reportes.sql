-- ============================================================
-- PROYECTO RESTAURANTE - PONTIFICIA UNIVERSIDAD JAVERIANA
-- SCRIPT DE CONSULTAS SQL, REPORTES Y ESTADÍSTICAS
-- ============================================================

\echo '============================================================'
\echo 'REPORTE 1: VENTAS TOTALES POR DÍA'
\echo 'Requisito: Caso de Uso 6 - Reporte de ventas totales por día'
\echo '============================================================'
SELECT 
    DATE(o.solicitado) AS fecha,
    COUNT(DISTINCT o.pedido_id) AS total_pedidos,
    SUM(o.cantidad) AS total_platos_vendidos,
    TO_CHAR(SUM(o.cantidad * p.precio), '$999,999,999') AS total_facturado,
    TO_CHAR(ROUND(SUM(o.cantidad * p.precio) / COUNT(DISTINCT o.pedido_id), 0), '$999,999') AS ticket_promedio
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
GROUP BY DATE(o.solicitado)
ORDER BY fecha DESC;


\echo ''
\echo '============================================================'
\echo 'REPORTE 2: VENTAS TOTALES POR SEMANA'
\echo 'Requisito: Caso de Uso 6 - Reporte de ventas por semana'
\echo '============================================================'
SELECT 
    TO_CHAR(o.solicitado, 'IYYY-"W"IW') AS anio_semana,
    DATE_TRUNC('week', o.solicitado)::DATE AS inicio_semana,
    (DATE_TRUNC('week', o.solicitado) + INTERVAL '6 days')::DATE AS fin_semana,
    COUNT(DISTINCT o.pedido_id) AS total_pedidos,
    SUM(o.cantidad) AS platos_vendidos,
    TO_CHAR(SUM(o.cantidad * p.precio), '$999,999,999') AS total_ventas_semana
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
GROUP BY TO_CHAR(o.solicitado, 'IYYY-"W"IW'), DATE_TRUNC('week', o.solicitado)
ORDER BY inicio_semana DESC;


\echo ''
\echo '============================================================'
\echo 'REPORTE 3: VENTAS TOTALES POR MES CON DESGLOSE POR CATEGORÍA'
\echo 'Requisito: Caso de Uso 6 - Reporte de ventas por mes'
\echo '============================================================'
SELECT 
    TO_CHAR(o.solicitado, 'YYYY-MM') AS periodo_mes,
    COUNT(DISTINCT o.pedido_id) AS total_pedidos,
    SUM(o.cantidad) AS total_unidades,
    TO_CHAR(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Entrada'), '$999,999') AS vtas_entradas,
    TO_CHAR(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Plato fuerte'), '$999,999') AS vtas_platos_fuertes,
    TO_CHAR(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Bebida'), '$999,999') AS vtas_bebidas,
    TO_CHAR(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Postre'), '$999,999') AS vtas_postres,
    TO_CHAR(SUM(o.cantidad * p.precio), '$999,999,999') AS total_facturado_mes
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN tipos t ON p.tipo_id = t.id
GROUP BY TO_CHAR(o.solicitado, 'YYYY-MM')
ORDER BY periodo_mes DESC;


\echo ''
\echo '============================================================'
\echo 'REPORTE 4: RANKING DE PLATOS MÁS SOLICITADOS'
\echo 'Requisito: Caso de Uso 6 - Reporte de pedidos más solicitados'
\echo '============================================================'
SELECT 
    DENSE_RANK() OVER (ORDER BY SUM(o.cantidad) DESC) AS ranking,
    p.nombre AS plato,
    t.nombre AS categoria,
    TO_CHAR(p.precio, '$999,999') AS precio_unitario,
    SUM(o.cantidad) AS unidades_vendidas,
    COUNT(DISTINCT o.pedido_id) AS pedidos_que_lo_incluyen,
    TO_CHAR(SUM(o.cantidad * p.precio), '$999,999,999') AS total_recaudado,
    ROUND((SUM(o.cantidad * p.precio) * 100.0) / SUM(SUM(o.cantidad * p.precio)) OVER (), 2) || '%' AS pct_ventas_totales
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN tipos t ON p.tipo_id = t.id
GROUP BY p.id, p.nombre, t.nombre, p.precio
ORDER BY unidades_vendidas DESC;


\echo ''
\echo '============================================================'
\echo 'REPORTE 5: HISTORIAL Y ESTADÍSTICAS DE RESERVACIONES'
\echo 'Requisito: Caso de Uso 6 - Reporte de reservaciones por día/mes'
\echo '============================================================'
SELECT 
    TO_CHAR(h.inicio, 'YYYY-MM') AS mes,
    COUNT(r.id) AS total_reservas,
    SUM(r.cantidad) AS total_comensales,
    COUNT(r.id) FILTER (WHERE r.estado = 0) AS reservadas_pendientes,
    COUNT(r.id) FILTER (WHERE r.estado = 1) AS ocupadas_actualmente,
    COUNT(r.id) FILTER (WHERE r.estado = 2) AS liberadas_atendidas,
    COUNT(r.id) FILTER (WHERE r.estado = 3) AS canceladas,
    ROUND((COUNT(r.id) FILTER (WHERE r.estado = 2) * 100.0) / NULLIF(COUNT(r.id), 0), 1) || '%' AS tasa_exito_atencion
FROM horarios h
JOIN reservaciones r ON h.reservacion_id = r.id
GROUP BY TO_CHAR(h.inicio, 'YYYY-MM')
ORDER BY mes DESC;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 6: CÁLCULO DEL CUPO TOTAL DEL RESTAURANTE'
\echo 'Requisito: Caso de Uso 1 - Calcular cupo total sumando asientos'
\echo '============================================================'
SELECT 
    COUNT(id) AS total_mesas,
    SUM(sillas) AS cupo_total_asientos,
    ROUND(AVG(sillas), 1) AS promedio_sillas_por_mesa,
    MIN(sillas) AS mesa_mas_pequena,
    MAX(sillas) AS mesa_mas_grande
FROM mesas;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 7: DISPONIBILIDAD DE MESAS EN UN RANGO HORARIO'
\echo 'Requisito: Caso de Uso 2 - Validar disponibilidad y cupo'
\echo 'Parámetros evaluados: Inicio 2026-10-26 14:00:00, Duración 90 min, 2 personas'
\echo '============================================================'
SELECT 
    m.id AS mesa_id,
    m.sillas AS capacidad_sillas,
    'DISPONIBLE' AS estado_disponibilidad
FROM mesas m
WHERE m.sillas >= 2 -- Capacidad requerida para 2 personas
  AND m.id NOT IN (
      -- Mesas con reserva activa en ese rango
      SELECT h.mesa_id
      FROM horarios h
      JOIN reservaciones r ON h.reservacion_id = r.id
      WHERE r.estado <> 3 -- Excluir canceladas
        AND tsrange(h.inicio, h.inicio + h.duracion) && tsrange('2026-10-26 14:00:00'::timestamp, '2026-10-26 14:00:00'::timestamp + INTERVAL '90 minutes')
  )
ORDER BY m.sillas ASC, m.id ASC;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 8: COMANDA DEL COCINERO (PRIORIDAD POR DEMORA)'
\echo 'Requisito: Rol Cocinero - Ver pedidos solicitados ordenados'
\echo 'por tiempo de preparación (más demorados primero), solo platos'
\echo 'que sabe preparar el cocinero (Ej: Cocinero Juan Martinez, ID 3)'
\echo '============================================================'
SELECT 
    o.id AS orden_id,
    o.pedido_id,
    p.nombre AS plato,
    t.nombre AS tipo,
    o.cantidad,
    p.tiempo AS tiempo_preparacion,
    o.solicitado AS fecha_hora_pedido,
    u_mesero.nombre AS mesero_solicitante
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN tipos t ON p.tipo_id = t.id
JOIN pedidos ped ON o.pedido_id = ped.id
JOIN usuarios u_mesero ON ped.mesero_id = u_mesero.id
WHERE o.estado = 0 -- 0 = Solicitado / Pendiente de preparación
  -- Solo platos donde el cocinero tenga la especialidad registrada
  AND p.id IN (
      SELECT e.plato_id
      FROM especialidades e
      WHERE e.cocinero_id = 3 -- Juan Martinez
  )
ORDER BY p.tiempo DESC, o.solicitado ASC;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 9: NOTIFICACIÓN DE PLATOS LISTOS PARA EL MESERO'
\echo 'Requisito: Caso de Uso 5 y Notificaciones - Notificar al mesero'
\echo 'cuando un pedido esté preparado para entregarlo a la mesa'
\echo '============================================================'
SELECT 
    o.id AS orden_id,
    o.pedido_id,
    p.nombre AS plato_listo,
    o.cantidad,
    u_cliente.nombre AS cliente,
    u_mesero.nombre AS mesero_asignado,
    TO_CHAR(o.solicitado, 'HH24:MI:SS') AS hora_solicitado,
    '¡LISTO PARA ENTREGAR!' AS alerta
FROM ordenes o
JOIN platos p ON o.plato_id = p.id
JOIN pedidos ped ON o.pedido_id = ped.id
JOIN usuarios u_cliente ON ped.cliente_id = u_cliente.id
JOIN usuarios u_mesero ON ped.mesero_id = u_mesero.id
WHERE o.estado = 1 -- 1 = Preparado / Listo para entrega
ORDER BY o.id ASC;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 10: NOTIFICACIÓN DE RESERVAS PRÓXIMAS AL MAITRE'
\echo 'Requisito: Notificaciones - Avisar al maitre reservas próximas'
\echo 'a comenzar en la ventana de alerta (ej: hoy 2026-10-26)'
\echo '============================================================'
SELECT 
    r.id AS reservacion_id,
    u.nombre AS cliente,
    h.mesa_id,
    m.sillas AS capacidad_mesa,
    r.cantidad AS comensales,
    h.inicio AS horario_reserva,
    h.duracion,
    CASE 
        WHEN h.inicio <= '2026-10-26 15:45:00'::timestamp THEN 'EN CURSO / INICIADA'
        ELSE 'PRÓXIMA A COMENZAR'
    END AS estado_alerta
FROM horarios h
JOIN reservaciones r ON h.reservacion_id = r.id
JOIN usuarios u ON r.cliente_id = u.id
JOIN mesas m ON h.mesa_id = m.id
WHERE DATE(h.inicio) = '2026-10-26'
  AND r.estado IN (0, 1) -- Reservada u Ocupada
ORDER BY h.inicio ASC;


\echo ''
\echo '============================================================'
\echo 'CONSULTA 11: HISTORIAL INTEGRAL DE UN CLIENTE'
\echo 'Requisito: Historial de Clientes - Reservaciones y consumos'
\echo 'Cliente evaluado: Carlos Perez (ID 1)'
\echo '============================================================'
SELECT 
    'RESERVA' AS tipo_evento,
    h.id AS referencia_id,
    h.inicio::text AS fecha_hora,
    'Mesa ' || h.mesa_id || ' (' || r.cantidad || ' pers.)' AS detalle,
    CASE r.estado 
        WHEN 0 THEN 'Reservada' 
        WHEN 1 THEN 'Ocupada' 
        WHEN 2 THEN 'Liberada' 
        WHEN 3 THEN 'Cancelada' 
    END AS estado,
    NULL AS valor_total
FROM reservaciones r
JOIN horarios h ON h.reservacion_id = r.id
WHERE r.cliente_id = 1

UNION ALL

SELECT 
    'PEDIDO' AS tipo_evento,
    p.id AS referencia_id,
    o.solicitado::text AS fecha_hora,
    STRING_AGG(pl.nombre || ' (x' || o.cantidad || ')', ', ') AS detalle,
    'Entregado' AS estado,
    TO_CHAR(SUM(o.cantidad * pl.precio), '$999,999') AS valor_total
FROM pedidos p
JOIN ordenes o ON o.pedido_id = p.id
JOIN platos pl ON o.plato_id = pl.id
WHERE p.cliente_id = 1
GROUP BY p.id, o.solicitado

ORDER BY fecha_hora DESC;
