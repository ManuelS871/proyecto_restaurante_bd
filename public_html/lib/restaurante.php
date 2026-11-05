<?php
/* ============================================================
   PROYECTO RESTAURANTE 
   MÓDULOS DE GESTIÓN Y CASOS DE USO POR ROLES
   ============================================================ */

/*------------------------------------------------------------------*/
/**
 * @brief Genera el botón para regresar al menú principal.
 */
function fn_boton_menu_principal()
{
    $scr_menu = "window.open('?opcion=', '_top');";
    return "<div style='margin-bottom:15px;'><button class='BTN_SM BTN_PRIMARIO' onClick=\"$scr_menu\">&larr; Menú Principal</button></div>";
}

/*------------------------------------------------------------------*/
/**
 * @brief Menú principal de navegación con acceso a todos los roles.
 */
function fn_menu_opciones($conn)
{
    $retorno = "<h1>Sistema de Gestión de Restaurante</h1>"
             . "<p style='color:#555;'>Seleccione una función o el rol correspondiente:</p>"
             . "<div class='MENU_PRINCIPAL_GRID'>"
             . "  <button onClick=\"window.open('?opcion=desplegar_menu', '_top');\">&#128214;<br/>Ver Menú</button>"
             . "  <button onClick=\"window.open('?opcion=realizar_pedidos', '_top');\">&#129346;<br/>Mesero<br/>(Pedidos)</button>"
             . "  <button onClick=\"window.open('?opcion=cocinero', '_top');\">&#128104;&#8205;&#127859;<br/>Cocinero<br/>(Comandas)</button>"
             . "  <button onClick=\"window.open('?opcion=maitre', '_top');\">&#128197;<br/>Maitre<br/>(Reservas)</button>"
             . "  <button onClick=\"window.open('?opcion=administrador', '_top');\">&#128110;<br/>Administrador<br/>(Gestión)</button>"
             . "  <button onClick=\"window.open('?opcion=reportes', '_top');\">&#128202;<br/>Reportes y<br/>Ventas</button>"
             . "  <button onClick=\"window.open('?opcion=historial_cliente', '_top');\">&#128100;<br/>Historial<br/>Cliente</button>"
             . "</div>";

    // Alerta rápida si hay pedidos listos esperando por mesero
    $q_listos = "SELECT COUNT(*) as cant FROM ordenes WHERE estado = 1;";
    $res_listos = procesar_query($q_listos, $conn);
    if ($res_listos->datos[0]['cant'] > 0) {
        $retorno .= "<div class='ALERTA_INFO'><strong>Aviso Mesero:</strong> Hay " . $res_listos->datos[0]['cant'] . " orden(es) preparada(s) en cocina lista(s) para ser entregada(s) a las mesas.</div>";
    }

    // Alerta rápida Maitre: reservas próximas a comenzar / en curso (ventana 60 min)
    $q_maitre_alert = "
        SELECT COUNT(*) AS cant,
               COUNT(*) FILTER (WHERE h.inicio <= NOW() AND (h.inicio + h.duracion) > NOW()) AS en_curso,
               COUNT(*) FILTER (WHERE h.inicio > NOW()) AS proximas
        FROM horarios h
        JOIN reservaciones r ON h.reservacion_id = r.id
        WHERE r.estado IN (0, 1)
          AND (h.inicio + h.duracion) > NOW()
          AND h.inicio <= NOW() + INTERVAL '60 minutes';
    ";
    $res_maitre_alert = procesar_query($q_maitre_alert, $conn);
    if ($res_maitre_alert->cantidad > 0 && $res_maitre_alert->datos[0]['cant'] > 0) {
        $cant_total = $res_maitre_alert->datos[0]['cant'];
        $en_curso = $res_maitre_alert->datos[0]['en_curso'];
        $proximas = $res_maitre_alert->datos[0]['proximas'];
        $clase_maitre = ($en_curso > 0) ? 'ALERTA_DANGER' : 'ALERTA_INFO';
        $retorno .= "<div class='" . $clase_maitre . "'><strong>Aviso Maitre:</strong> Hay " . $cant_total . " reserva(s) activa(s) en ventana de 60 min"
                  . " (" . $en_curso . " en curso / " . $proximas . " próxima(s) a comenzar) "
                  . "<a href='?opcion=maitre' class='BTN_SM BTN_PRIMARIO' style='vertical-align:middle; margin-left:8px;'>Revisar reservas</a></div>";
    }

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief Visualiza la carta de platos agrupados por tipo (Función original del parcial).
 */
function fn_desplegar_menu($conn)
{
    $sentencia = "
    SELECT platos.id
         , platos.nombre
         , tipos.nombre AS tipo
         , descripcion
         , precio 
    FROM platos
    LEFT JOIN tipos ON platos.tipo_id=tipos.id
    ORDER BY tipos.id, platos.nombre
    ;";
    $resultado = procesar_query($sentencia, $conn);
    $retorno = fn_boton_menu_principal();
    $tipo = "";
    
    $retorno .= "<div style='text-align:center;'>";
    foreach ($resultado->datos as $plato) {
        if ($tipo != $plato['tipo']) {
            if ($tipo != "") $retorno .= "</div>";
            $retorno .= "<div class='SECCION'><h2>" . htmlspecialchars($plato['tipo']) . "</h2>";
            $tipo = $plato['tipo'];
        }
        $retorno .= "<div class='PLATO'>"
                 .     "<span class='NOMBRE'>" . htmlspecialchars($plato['nombre']) . "</span>"
                 .     "<span class='DESCRIPCION'>" . htmlspecialchars($plato['descripcion']) . "</span>"
                 .     "<span class='PRECIO'>$" . number_format($plato['precio'], 0, ',', '.') . "</span>"
                 . "</div>";
    }
    if ($tipo != "") $retorno .= "</div>";
    $retorno .= "</div>";
    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief ROL MESERO: Registro de pedidos y entrega de órdenes listas.
 */
function fn_realizar_pedidos($conn)
{
    $retorno = fn_boton_menu_principal();
    $retorno .= "<h2>Módulo de Mesero: Gestión de Pedidos y Entregas</h2>";

    $mensaje = "";

    // PROCESAR ACCIONES
    if (isset($_POST['accion'])) {
        if ($_POST['accion'] == 'crear_pedido_orden') {
            $mesa_id = intval($_POST['mesa_id']);
            $cliente_id = intval($_POST['cliente_id']);
            $mesero_id = intval($_POST['mesero_id']);
            $plato_id = intval($_POST['plato_id']);
            $cantidad = intval($_POST['cantidad']);

            if ($cantidad > 0) {
                // Verificar si ya existe un pedido abierto para este cliente y mesero hoy
                $q_ped = "SELECT id FROM pedidos WHERE cliente_id = $cliente_id AND mesero_id = $mesero_id ORDER BY id DESC LIMIT 1;";
                $res_ped = procesar_query($q_ped, $conn);
                
                $pedido_id = 0;
                if ($res_ped->cantidad > 0) {
                    $pedido_id = $res_ped->datos[0]['id'];
                } else {
                    $q_new_ped = "INSERT INTO pedidos (cliente_id, mesero_id) VALUES ($cliente_id, $mesero_id) RETURNING id;";
                    $res_ins = @pg_query($conn, $q_new_ped);
                    if ($res_ins) {
                        $fila = pg_fetch_assoc($res_ins);
                        $pedido_id = $fila['id'];
                    } else {
                        $mensaje = "<div class='ALERTA_DANGER'>Error al crear pedido (Trigger Mesero): " . htmlspecialchars(pg_last_error($conn)) . "</div>";
                    }
                }

                if ($pedido_id > 0) {
                    $q_ord = "INSERT INTO ordenes (plato_id, pedido_id, estado, cantidad, solicitado) VALUES ($plato_id, $pedido_id, 0, $cantidad, NOW());";
                    $res_ord = @pg_query($conn, $q_ord);
                    if ($res_ord) {
                        $mensaje = "<div class='ALERTA_SUCCESS'>¡Orden enviada a cocina exitosamente!</div>";
                    } else {
                        $mensaje = "<div class='ALERTA_DANGER'>Error al agregar orden: " . htmlspecialchars(pg_last_error($conn)) . "</div>";
                    }
                }
            }
        } elseif ($_POST['accion'] == 'entregar_orden') {
            $orden_id = intval($_POST['orden_id']);
            // El trigger trg_validar_transicion_orden validará que esté en estado 1 (preparado)
            $q_upd = "UPDATE ordenes SET estado = 2 WHERE id = $orden_id;";
            $res_upd = @pg_query($conn, $q_upd);
            if ($res_upd) {
                $mensaje = "<div class='ALERTA_SUCCESS'>Orden #$orden_id entregada a la mesa con éxito.</div>";
            } else {
                $mensaje = "<div class='ALERTA_DANGER'>Error al entregar (Trigger): " . htmlspecialchars(pg_last_error($conn)) . "</div>";
            }
        }
    }

    $retorno .= $mensaje;

    // 1. NOTIFICACIONES AL MESERO: Órdenes listas para entrega
    $q_listas = "
        SELECT o.id AS orden_id, o.pedido_id, p.nombre AS plato, o.cantidad, 
               uc.nombre AS cliente, um.nombre AS mesero,
               TO_CHAR(o.solicitado, 'HH24:MI') AS hora
        FROM ordenes o
        JOIN platos p ON o.plato_id = p.id
        JOIN pedidos ped ON o.pedido_id = ped.id
        JOIN usuarios uc ON ped.cliente_id = uc.id
        JOIN usuarios um ON ped.mesero_id = um.id
        WHERE o.estado = 1
        ORDER BY o.id ASC;
    ";
    $res_listas = procesar_query($q_listas, $conn);
    if ($res_listas->cantidad > 0) {
        $retorno .= "<div class='ALERTA_INFO'><strong>&#128276; Notificación: ¡Platos listos para servir!</strong>";
        $retorno .= "<table class='TABLA_GESTION' style='margin-top:10px;'><tr><th>Orden</th><th>Plato</th><th>Cant.</th><th>Cliente</th><th>Mesero</th><th>Hora</th><th>Acción</th></tr>";
        foreach ($res_listas->datos as $ord_l) {
            $retorno .= "<tr>"
                     . "<td>#" . $ord_l['orden_id'] . "</td>"
                     . "<td><strong>" . htmlspecialchars($ord_l['plato']) . "</strong></td>"
                     . "<td>" . $ord_l['cantidad'] . "</td>"
                     . "<td>" . htmlspecialchars($ord_l['cliente']) . "</td>"
                     . "<td>" . htmlspecialchars($ord_l['mesero']) . "</td>"
                     . "<td>" . $ord_l['hora'] . "</td>"
                     . "<td><form method='POST' style='display:inline;'><input type='hidden' name='accion' value='entregar_orden'/><input type='hidden' name='orden_id' value='" . $ord_l['orden_id'] . "'/><button type='submit' class='BTN_SM BTN_EXITO'>&#10004; Entregar</button></form></td>"
                     . "</tr>";
        }
        $retorno .= "</table></div>";
    }

    // 2. LISTADO DE MESAS CON RESERVAS DE HOY
    $mesa_seleccionada = isset($_REQUEST['mesa_id']) ? intval($_REQUEST['mesa_id']) : 0;

    $sentencia = "
        SELECT MES.id, MES.sillas, HOR.inicio, USR.nombre as nombre_usr, RES.cliente_id
        FROM mesas AS MES
        LEFT JOIN horarios AS HOR ON MES.id = HOR.mesa_id
        LEFT JOIN reservaciones AS RES ON RES.id = HOR.reservacion_id
        LEFT JOIN usuarios AS USR ON USR.id = RES.cliente_id
        WHERE HOR.inicio BETWEEN CURRENT_DATE AND CURRENT_DATE + INTERVAL '1 day'
        ORDER BY MES.id;
    ";
    $resultado = procesar_query($sentencia, $conn);

    $retorno .= "<p>Seleccione una mesa para registrar o consultar pedidos:</p>";
    $retorno .= "<div style='display:flex; flex-wrap:wrap; justify-content:center; gap:10px; margin-bottom:20px;'>";
    
    // Mesas activas según consulta original
    $mesas_mostradas = array();
    foreach ($resultado->datos as $mesa) {
        $mesas_mostradas[] = $mesa['id'];
        $activo_class = ($mesa_seleccionada == $mesa['id']) ? "BTN_PRIMARIO" : "BTN_ALERTA";
        $script = "window.open('?opcion=realizar_pedidos&mesa_id=" . $mesa['id'] . "', '_top');";
        $retorno .= "<button class='BTN_SM $activo_class' style='padding:12px 18px; font-size:1em;' onClick=\"$script\">Mesa " . $mesa['id'] . " (" . htmlspecialchars($mesa['nombre_usr']) . ")</button>";
    }

    // Permitir seleccionar también cualquier mesa del restaurante
    $q_todas = "SELECT id, sillas FROM mesas ORDER BY id;";
    $res_todas = procesar_query($q_todas, $conn);
    foreach ($res_todas->datos as $m) {
        if (!in_array($m['id'], $mesas_mostradas)) {
            $activo_class = ($mesa_seleccionada == $m['id']) ? "BTN_PRIMARIO" : "";
            $script = "window.open('?opcion=realizar_pedidos&mesa_id=" . $m['id'] . "', '_top');";
            $retorno .= "<button class='BTN_SM $activo_class' style='padding:12px 18px;' onClick=\"$script\">Mesa " . $m['id'] . " (" . $m['sillas'] . " sillas)</button>";
        }
    }
    $retorno .= "</div>";

    // 3. SI HAY MESA SELECCIONADA: FORMULARIO DE TOMA DE PEDIDO
    if ($mesa_seleccionada > 0) {
        $retorno .= "<div class='FORM_CARD'>";
        $retorno .= "<h3>Tomar Pedido para Mesa #" . $mesa_seleccionada . "</h3>";
        $retorno .= "<form method='POST'>";
        $retorno .= "<input type='hidden' name='accion' value='crear_pedido_orden'/>";
        $retorno .= "<input type='hidden' name='mesa_id' value='$mesa_seleccionada'/>";

        // Selector de Cliente
        $retorno .= "<label>Cliente:</label><select name='cliente_id' required>";
        $q_cli = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cliente' ORDER BY u.nombre;";
        $res_cli = procesar_query($q_cli, $conn);
        foreach ($res_cli->datos as $cli) {
            $retorno .= "<option value='" . $cli['id'] . "'>" . htmlspecialchars($cli['nombre']) . "</option>";
        }
        $retorno .= "</select>";

        // Selector de Mesero (Validado con el rol)
        $retorno .= "<label>Mesero Responsable:</label><select name='mesero_id' required>";
        $q_mes = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'mesero' ORDER BY u.nombre;";
        $res_mes = procesar_query($q_mes, $conn);
        foreach ($res_mes->datos as $mes) {
            $retorno .= "<option value='" . $mes['id'] . "'>" . htmlspecialchars($mes['nombre']) . "</option>";
        }
        $retorno .= "</select>";

        // Selector de Plato
        $retorno .= "<label>Plato / Bebida:</label><select name='plato_id' required>";
        $q_pl = "SELECT p.id, p.nombre, t.nombre AS tipo, p.precio FROM platos p JOIN tipos t ON p.tipo_id = t.id ORDER BY t.id, p.nombre;";
        $res_pl = procesar_query($q_pl, $conn);
        foreach ($res_pl->datos as $pl) {
            $retorno .= "<option value='" . $pl['id'] . "'>" . htmlspecialchars($pl['nombre']) . " (" . $pl['tipo'] . " - $" . number_format($pl['precio'], 0, ',', '.') . ")</option>";
        }
        $retorno .= "</select>";

        $retorno .= "<label>Cantidad:</label><input type='number' name='cantidad' min='1' max='20' value='1' required/>";
        $retorno .= "<div style='margin-top:15px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>&#10133; Enviar Pedido a Cocina</button></div>";
        $retorno .= "</form></div>";

        // Órdenes recientes asociadas a pedidos de hoy
        $retorno .= "<h3>Órdenes en Proceso / Servidas</h3>";
        $q_ord_mesa = "
            SELECT o.id, p.nombre AS plato, o.cantidad, o.estado, o.solicitado, uc.nombre AS cliente, um.nombre AS mesero
            FROM ordenes o
            JOIN platos p ON o.plato_id = p.id
            JOIN pedidos ped ON o.pedido_id = ped.id
            JOIN usuarios uc ON ped.cliente_id = uc.id
            JOIN usuarios um ON ped.mesero_id = um.id
            WHERE o.solicitado >= CURRENT_DATE - INTERVAL '1 day'
            ORDER BY o.id DESC LIMIT 10;
        ";
        $res_om = procesar_query($q_ord_mesa, $conn);
        $retorno .= "<table class='TABLA_GESTION'><tr><th>ID</th><th>Plato</th><th>Cant.</th><th>Estado</th><th>Cliente</th><th>Mesero</th><th>Acción</th></tr>";
        foreach ($res_om->datos as $om) {
            $badge = "";
            $accion_btn = "";
            if ($om['estado'] == 0) {
                $badge = "<span class='BADGE BADGE_SOLICITADO'>En preparación</span>";
            } elseif ($om['estado'] == 1) {
                $badge = "<span class='BADGE BADGE_LISTO'>Listo en Cocina</span>";
                $accion_btn = "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='entregar_orden'/><input type='hidden' name='orden_id' value='" . $om['id'] . "'/><button type='submit' class='BTN_SM BTN_EXITO'>Entregar</button></form>";
            } else {
                $badge = "<span class='BADGE BADGE_ENTREGADO'>Entregado</span>";
            }
            $retorno .= "<tr>"
                     . "<td>#" . $om['id'] . "</td>"
                     . "<td>" . htmlspecialchars($om['plato']) . "</td>"
                     . "<td>" . $om['cantidad'] . "</td>"
                     . "<td>" . $badge . "</td>"
                     . "<td>" . htmlspecialchars($om['cliente']) . "</td>"
                     . "<td>" . htmlspecialchars($om['mesero']) . "</td>"
                     . "<td>$accion_btn</td>"
                     . "</tr>";
        }
        $retorno .= "</table>";
    }

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief ROL COCINERO: Comandas filtradas por especialidad y ordenadas por tiempo desc.
 */
function fn_cocinero($conn)
{
    $retorno = fn_boton_menu_principal();
    $retorno .= "<h2>Módulo de Cocinero: Comandas y Preparación</h2>";

    $mensaje = "";

    // PROCESAR MARCADO COMO LISTO / PREPARADO
    if (isset($_POST['accion']) && $_POST['accion'] == 'marcar_preparado') {
        $orden_id = intval($_POST['orden_id']);
        $cocinero_id = intval($_POST['cocinero_id']);

        // Registrar preparación (trigger valida rol y especialidad)
        $q_prep = "INSERT INTO preparaciones (cocinero_id, orden_id) VALUES ($cocinero_id, $orden_id);";
        $res_p = @pg_query($conn, $q_prep);
        if ($res_p) {
            // Actualizar orden a estado 1 (preparado / listo)
            $q_upd = "UPDATE ordenes SET estado = 1 WHERE id = $orden_id;";
            @pg_query($conn, $q_upd);
            $mensaje = "<div class='ALERTA_SUCCESS'>¡Orden #$orden_id preparada! Se ha notificado al mesero para su entrega.</div>";
        } else {
            $mensaje = "<div class='ALERTA_DANGER'>Error al registrar preparación (Trigger Cocinero): " . htmlspecialchars(pg_last_error($conn)) . "</div>";
        }
    }

    $retorno .= $mensaje;

    // Selector de cocinero activo
    $cocinero_id = isset($_REQUEST['cocinero_id']) ? intval($_REQUEST['cocinero_id']) : 0;
    $q_cocs = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cocinero' ORDER BY u.nombre;";
    $res_cocs = procesar_query($q_cocs, $conn);

    if ($cocinero_id == 0 && $res_cocs->cantidad > 0) {
        $cocinero_id = $res_cocs->datos[0]['id'];
    }

    $retorno .= "<div style='margin-bottom:15px; text-align:center;'>";
    $retorno .= "<form method='GET' style='display:inline;'>";
    $retorno .= "<input type='hidden' name='opcion' value='cocinero'/>";
    $retorno .= "<label style='font-weight:bold; margin-right:10px;'>Cocinero en turno:</label>";
    $retorno .= "<select name='cocinero_id' onChange='this.form.submit()' style='padding:6px; font-size:1em; border-radius:5px;'>";
    foreach ($res_cocs->datos as $c) {
        $sel = ($cocinero_id == $c['id']) ? "selected" : "";
        $retorno .= "<option value='" . $c['id'] . "' $sel>" . htmlspecialchars($c['nombre']) . "</option>";
    }
    $retorno .= "</select></form></div>";

    // Mostrar especialidades del cocinero
    $q_esp = "SELECT p.nombre FROM especialidades e JOIN platos p ON e.plato_id = p.id WHERE e.cocinero_id = $cocinero_id ORDER BY p.nombre;";
    $res_esp = procesar_query($q_esp, $conn);
    $esp_nombres = array();
    foreach ($res_esp->datos as $esp) {
        $esp_nombres[] = $esp['nombre'];
    }

    $retorno .= "<div class='ALERTA_INFO' style='text-align:center;'><strong>Tus Especialidades:</strong> " 
             . (count($esp_nombres) > 0 ? implode(" &bull; ", $esp_nombres) : "<em>Ninguna asignada</em>")
             . "</div>";

    // CONSULTA 8: Comandas solicitadas ordenadas por tiempo de preparación desc (más demoradas primero)
    $q_comanda = "
        SELECT o.id AS orden_id, o.pedido_id, p.nombre AS plato, t.nombre AS tipo, o.cantidad, 
               p.tiempo AS tiempo_prep, o.solicitado, um.nombre AS mesero
        FROM ordenes o
        JOIN platos p ON o.plato_id = p.id
        JOIN tipos t ON p.tipo_id = t.id
        JOIN pedidos ped ON o.pedido_id = ped.id
        JOIN usuarios um ON ped.mesero_id = um.id
        WHERE o.estado = 0
          AND p.id IN (SELECT plato_id FROM especialidades WHERE cocinero_id = $cocinero_id)
        ORDER BY p.tiempo DESC, o.solicitado ASC;
    ";
    $res_com = procesar_query($q_comanda, $conn);

    $retorno .= "<h3>Comandas Pendientes (Ordenadas por Tiempo de Preparación)</h3>";
    if ($res_com->cantidad == 0) {
        $retorno .= "<p style='color:#666;'>No hay órdenes pendientes en este momento para tus especialidades.</p>";
    } else {
        $retorno .= "<table class='TABLA_GESTION'>";
        $retorno .= "<tr><th>Prioridad / Tiempo</th><th>Orden</th><th>Plato</th><th>Categoría</th><th>Cantidad</th><th>Mesero</th><th>Acción</th></tr>";
        foreach ($res_com->datos as $com) {
            $retorno .= "<tr>"
                     . "<td><span style='color:#b91c1c; font-weight:bold;'>&#9201; " . $com['tiempo_prep'] . "</span></td>"
                     . "<td>#" . $com['orden_id'] . "</td>"
                     . "<td><strong>" . htmlspecialchars($com['plato']) . "</strong></td>"
                     . "<td>" . htmlspecialchars($com['tipo']) . "</td>"
                     . "<td>" . $com['cantidad'] . "</td>"
                     . "<td>" . htmlspecialchars($com['mesero']) . "</td>"
                     . "<td>"
                     . "  <form method='POST' style='margin:0;'>"
                     . "    <input type='hidden' name='accion' value='marcar_preparado'/>"
                     . "    <input type='hidden' name='orden_id' value='" . $com['orden_id'] . "'/>"
                     . "    <input type='hidden' name='cocinero_id' value='$cocinero_id'/>"
                     . "    <button type='submit' class='BTN_SM BTN_EXITO'>&#10004; Listo</button>"
                     . "  </form>"
                     . "</td>"
                     . "</tr>";
        }
        $retorno .= "</table>";
    }

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief ROL MAITRE: Gestión de Reservaciones, Asignación de Mesas y Notificaciones.
 */
function fn_maitre($conn)
{
    $retorno = fn_boton_menu_principal();
    $retorno .= "<h2>Módulo de Maitre: Gestión de Reservaciones y Mesas</h2>";

    $mensaje = "";

    // PROCESAR ACCIONES
    if (isset($_POST['accion'])) {
        if ($_POST['accion'] == 'crear_reservacion') {
            $cliente_id = intval($_POST['cliente_id']);
            $cantidad = intval($_POST['cantidad']);
            $mesa_id = intval($_POST['mesa_id']);
            $inicio = pg_escape_string($conn, $_POST['fecha'] . " " . $_POST['hora']);
            $duracion = pg_escape_string($conn, $_POST['duracion_minutos'] . " minutes");

            // Iniciar transacción
            @pg_query($conn, "BEGIN;");
            $q_res = "INSERT INTO reservaciones (cliente_id, cantidad, estado) VALUES ($cliente_id, $cantidad, 0) RETURNING id;";
            $res_r = @pg_query($conn, $q_res);
            if ($res_r) {
                $fila = pg_fetch_assoc($res_r);
                $reserva_id = $fila['id'];

                $q_hor = "INSERT INTO horarios (mesa_id, reservacion_id, inicio, duracion) VALUES ($mesa_id, $reserva_id, '$inicio', INTERVAL '$duracion');";
                $res_h = @pg_query($conn, $q_hor);
                if ($res_h) {
                    @pg_query($conn, "COMMIT;");
                    $mensaje = "<div class='ALERTA_SUCCESS'>¡Reservación #$reserva_id asignada a la Mesa #$mesa_id exitosamente!</div>";
                } else {
                    $err = pg_last_error($conn);
                    @pg_query($conn, "ROLLBACK;");
                    $mensaje = "<div class='ALERTA_DANGER'>Error en validación de horario (Trigger): " . htmlspecialchars($err) . "</div>";
                }
            } else {
                $err = pg_last_error($conn);
                @pg_query($conn, "ROLLBACK;");
                $mensaje = "<div class='ALERTA_DANGER'>Error al crear reservación: " . htmlspecialchars($err) . "</div>";
            }
        } elseif ($_POST['accion'] == 'cambiar_estado') {
            $reserva_id = intval($_POST['reserva_id']);
            $nuevo_estado = intval($_POST['nuevo_estado']);
            $q_upd = "UPDATE reservaciones SET estado = $nuevo_estado WHERE id = $reserva_id;";
            if (@pg_query($conn, $q_upd)) {
                $mensaje = "<div class='ALERTA_SUCCESS'>Estado de la reservación #$reserva_id actualizado.</div>";
            }
        } elseif ($_POST['accion'] == 'editar_reservacion') {
            $reserva_id = intval($_POST['reserva_id']);
            $cliente_id = intval($_POST['cliente_id']);
            $cantidad = intval($_POST['cantidad']);
            $mesa_id = intval($_POST['mesa_id']);
            $nuevo_estado = intval($_POST['estado']);
            $inicio = pg_escape_string($conn, $_POST['fecha'] . " " . $_POST['hora']);
            $duracion = pg_escape_string($conn, $_POST['duracion_minutos'] . " minutes");

            @pg_query($conn, "BEGIN;");
            $q_upd_r = "UPDATE reservaciones SET cliente_id = $cliente_id, cantidad = $cantidad, estado = $nuevo_estado WHERE id = $reserva_id;";
            $res_ur = @pg_query($conn, $q_upd_r);

            $q_upd_h = "UPDATE horarios SET mesa_id = $mesa_id, inicio = '$inicio', duracion = INTERVAL '$duracion' WHERE reservacion_id = $reserva_id;";
            $res_uh = @pg_query($conn, $q_upd_h);

            if ($res_ur && $res_uh) {
                @pg_query($conn, "COMMIT;");
                $mensaje = "<div class='ALERTA_SUCCESS'>¡Reservación #$reserva_id modificada exitosamente!</div>";
            } else {
                $err = pg_last_error($conn);
                @pg_query($conn, "ROLLBACK;");
                $mensaje = "<div class='ALERTA_DANGER'>Error al modificar la reservación (Validación de negocio / Trigger): " . htmlspecialchars($err) . "</div>";
            }
        }
    }

    $retorno .= $mensaje;

    // NOTIFICACIÓN URGENTE (Funcionalidades Adicionales #1): reservas próximas a comenzar / en curso
    // Ventana de alerta: las que ya comenzaron y siguen ocupando la mesa + las que comienzan en los próximos 60 minutos.
    $q_alert_proxima = "
        SELECT r.id AS reservacion_id, u.nombre AS cliente, h.mesa_id, m.sillas, r.cantidad,
               TO_CHAR(h.inicio, 'HH24:MI') AS hora_reserva, h.inicio, h.duracion, r.estado,
               CASE
                   WHEN h.inicio <= NOW() AND (h.inicio + h.duracion) > NOW() THEN 'EN_CURSO'
                   ELSE 'PROXIMA'
               END AS estado_alerta,
               CASE
                   WHEN h.inicio > NOW() THEN GREATEST(0, ROUND(EXTRACT(EPOCH FROM (h.inicio - NOW()))/60))::int
                   ELSE 0
               END AS minutos_restantes,
               CASE
                   WHEN h.inicio <= NOW() THEN GREATEST(0, ROUND(EXTRACT(EPOCH FROM (NOW() - h.inicio))/60))::int
                   ELSE 0
               END AS minutos_desde_inicio
        FROM horarios h
        JOIN reservaciones r ON h.reservacion_id = r.id
        JOIN usuarios u ON r.cliente_id = u.id
        JOIN mesas m ON h.mesa_id = m.id
        WHERE r.estado IN (0, 1)
          AND (h.inicio + h.duracion) > NOW()
          AND h.inicio <= NOW() + INTERVAL '60 minutes'
        ORDER BY h.inicio ASC;
    ";
    $res_ap = procesar_query($q_alert_proxima, $conn);
    if ($res_ap->cantidad > 0) {
        $tiene_en_curso = false;
        foreach ($res_ap->datos as $ap) {
            if ($ap['estado_alerta'] == 'EN_CURSO') { $tiene_en_curso = true; break; }
        }
        $clase_alerta = $tiene_en_curso ? 'ALERTA_DANGER' : 'ALERTA_INFO';
        $retorno .= "<div class='" . $clase_alerta . "'><strong>&#128276; ¡Atención Maitre! Reservaciones próximas a comenzar (próximos 60 min) / en curso:</strong>";
        $retorno .= "<table class='TABLA_GESTION' style='margin-top:10px;'><tr><th>Reserva</th><th>Cliente</th><th>Mesa</th><th>Comensales</th><th>Hora</th><th>Alerta</th><th>Falta / Lleva</th><th>Estado</th><th>Acción</th></tr>";
        foreach ($res_ap->datos as $rh) {
            $es_curso = ($rh['estado_alerta'] == 'EN_CURSO');
            $badge_alerta = $es_curso ? "<span class='BADGE BADGE_LISTO'>EN CURSO</span>" : "<span class='BADGE BADGE_SOLICITADO'>PRÓXIMA</span>";
            $tiempo_txt = $es_curso ? "iniciada hace " . $rh['minutos_desde_inicio'] . " min" : "comienza en " . $rh['minutos_restantes'] . " min";
            $est_txt = ($rh['estado'] == 0) ? "<span class='BADGE BADGE_SOLICITADO'>Reservada</span>" : "<span class='BADGE BADGE_LISTO'>Ocupada</span>";
            $retorno .= "<tr>"
                     . "<td>#" . $rh['reservacion_id'] . "</td>"
                     . "<td>" . htmlspecialchars($rh['cliente']) . "</td>"
                     . "<td>Mesa " . $rh['mesa_id'] . "</td>"
                     . "<td>" . $rh['cantidad'] . " / " . $rh['sillas'] . " sillas</td>"
                     . "<td>" . $rh['hora_reserva'] . "</td>"
                     . "<td>$badge_alerta</td>"
                     . "<td><strong>" . $tiempo_txt . "</strong></td>"
                     . "<td>$est_txt</td>"
                     . "<td>";
            $retorno .= "<a href='?opcion=maitre&edit_reserva_id=" . $rh['reservacion_id'] . "' class='BTN_SM BTN_ALERTA'>Modificar</a> ";
            if ($rh['estado'] == 0) {
                $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='1'/><button type='submit' class='BTN_SM BTN_EXITO'>Ocupar</button></form> ";
            } elseif ($rh['estado'] == 1) {
                $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='2'/><button type='submit' class='BTN_SM BTN_PRIMARIO'>Liberar</button></form> ";
            }
            $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='3'/><button type='submit' class='BTN_SM BTN_PELIGRO'>Cancelar</button></form>";
            $retorno .= "</td></tr>";
        }
        $retorno .= "</table></div>";
    }

    // NOTIFICACIÓN: Reservaciones de hoy próximas a iniciar
    $q_alert_hoy = "
        SELECT r.id AS reservacion_id, u.nombre AS cliente, h.mesa_id, m.sillas, r.cantidad,
               TO_CHAR(h.inicio, 'HH24:MI') AS hora_reserva, r.estado
        FROM horarios h
        JOIN reservaciones r ON h.reservacion_id = r.id
        JOIN usuarios u ON r.cliente_id = u.id
        JOIN mesas m ON h.mesa_id = m.id
        WHERE DATE(h.inicio) = CURRENT_DATE
          AND r.estado IN (0, 1)
        ORDER BY h.inicio ASC;
    ";
    $res_ah = procesar_query($q_alert_hoy, $conn);
    if ($res_ah->cantidad > 0) {
        $retorno .= "<div class='ALERTA_INFO'><strong>&#128276; Reservaciones para el día de hoy:</strong>";
        $retorno .= "<table class='TABLA_GESTION' style='margin-top:10px;'><tr><th>Reserva</th><th>Cliente</th><th>Mesa</th><th>Comensales</th><th>Hora</th><th>Estado</th><th>Acción</th></tr>";
        foreach ($res_ah->datos as $rh) {
            $est_txt = ($rh['estado'] == 0) ? "<span class='BADGE BADGE_SOLICITADO'>Reservada</span>" : "<span class='BADGE BADGE_LISTO'>Ocupada</span>";
            $retorno .= "<tr>"
                     . "<td>#" . $rh['reservacion_id'] . "</td>"
                     . "<td>" . htmlspecialchars($rh['cliente']) . "</td>"
                     . "<td>Mesa " . $rh['mesa_id'] . "</td>"
                     . "<td>" . $rh['cantidad'] . " / " . $rh['sillas'] . " sillas</td>"
                     . "<td>" . $rh['hora_reserva'] . "</td>"
                     . "<td>$est_txt</td>"
                     . "<td>";
            $retorno .= "<a href='?opcion=maitre&edit_reserva_id=" . $rh['reservacion_id'] . "' class='BTN_SM BTN_ALERTA'>Modificar</a> ";
            if ($rh['estado'] == 0) {
                $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='1'/><button type='submit' class='BTN_SM BTN_EXITO'>Ocupar</button></form> ";
            } elseif ($rh['estado'] == 1) {
                $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='2'/><button type='submit' class='BTN_SM BTN_PRIMARIO'>Liberar</button></form> ";
            }
            $retorno .= "<form method='POST' style='display:inline;'><input type='hidden' name='accion' value='cambiar_estado'/><input type='hidden' name='reserva_id' value='" . $rh['reservacion_id'] . "'/><input type='hidden' name='nuevo_estado' value='3'/><button type='submit' class='BTN_SM BTN_PELIGRO'>Cancelar</button></form>";
            $retorno .= "</td></tr>";
        }
        $retorno .= "</table></div>";
    }

    // FORMULARIO: REGISTRAR O MODIFICAR RESERVACIÓN
    $edit_reserva_id = isset($_GET['edit_reserva_id']) ? intval($_GET['edit_reserva_id']) : 0;
    $datos_edicion = null;
    if ($edit_reserva_id > 0) {
        $q_cur_res = "
            SELECT r.id, r.cliente_id, r.cantidad, r.estado,
                   h.mesa_id,
                   TO_CHAR(h.inicio, 'YYYY-MM-DD') AS fecha,
                   TO_CHAR(h.inicio, 'HH24:MI') AS hora,
                   ROUND(EXTRACT(EPOCH FROM h.duracion)/60) AS duracion_minutos
            FROM reservaciones r
            JOIN horarios h ON h.reservacion_id = r.id
            WHERE r.id = $edit_reserva_id;
        ";
        $res_cur_res = procesar_query($q_cur_res, $conn);
        if ($res_cur_res->cantidad > 0) {
            $datos_edicion = $res_cur_res->datos[0];
        }
    }

    if ($datos_edicion) {
        $retorno .= "<div class='FORM_CARD' style='border: 2px solid #d97706;'>";
        $retorno .= "<h3>Modificar Reservación #" . $datos_edicion['id'] . "</h3>";
        $retorno .= "<form method='POST'>";
        $retorno .= "<input type='hidden' name='accion' value='editar_reservacion'/>";
        $retorno .= "<input type='hidden' name='reserva_id' value='" . $datos_edicion['id'] . "'/>";

        // Selector cliente
        $retorno .= "<label>Cliente:</label><select name='cliente_id' required>";
        $q_cli = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cliente' ORDER BY u.nombre;";
        $res_cli = procesar_query($q_cli, $conn);
        foreach ($res_cli->datos as $cli) {
            $sel = ($cli['id'] == $datos_edicion['cliente_id']) ? "selected" : "";
            $retorno .= "<option value='" . $cli['id'] . "' $sel>" . htmlspecialchars($cli['nombre']) . "</option>";
        }
        $retorno .= "</select>";

        $retorno .= "<label>Cantidad de Comensales:</label><input type='number' name='cantidad' min='1' max='20' value='" . $datos_edicion['cantidad'] . "' required/>";

        // Selector mesa
        $retorno .= "<label>Mesa asignada:</label><select name='mesa_id' required>";
        $q_mes = "SELECT id, sillas FROM mesas ORDER BY id;";
        $res_mes = procesar_query($q_mes, $conn);
        foreach ($res_mes->datos as $m) {
            $sel = ($m['id'] == $datos_edicion['mesa_id']) ? "selected" : "";
            $retorno .= "<option value='" . $m['id'] . "' $sel>Mesa " . $m['id'] . " (" . $m['sillas'] . " sillas)</option>";
        }
        $retorno .= "</select>";

        $retorno .= "<label>Fecha:</label><input type='date' name='fecha' value='" . $datos_edicion['fecha'] . "' required/>";
        $retorno .= "<label>Hora de Inicio:</label><input type='time' name='hora' value='" . $datos_edicion['hora'] . "' required/>";
        
        $dur_actual = intval($datos_edicion['duracion_minutos']);
        $retorno .= "<label>Duración estimada (máx 2 horas según regla):</label><select name='duracion_minutos'>";
        $retorno .= "<option value='60' " . ($dur_actual == 60 ? 'selected' : '') . ">1 hora (60 min)</option>";
        $retorno .= "<option value='90' " . ($dur_actual == 90 ? 'selected' : '') . ">1 hora y media (90 min)</option>";
        $retorno .= "<option value='120' " . ($dur_actual == 120 ? 'selected' : '') . ">2 horas (120 min)</option>";
        $retorno .= "</select>";

        // Selector estado
        $retorno .= "<label>Estado de la Reserva:</label><select name='estado' required>";
        $retorno .= "<option value='0' " . ($datos_edicion['estado'] == 0 ? 'selected' : '') . ">Reservada</option>";
        $retorno .= "<option value='1' " . ($datos_edicion['estado'] == 1 ? 'selected' : '') . ">Ocupada</option>";
        $retorno .= "<option value='2' " . ($datos_edicion['estado'] == 2 ? 'selected' : '') . ">Liberada</option>";
        $retorno .= "<option value='3' " . ($datos_edicion['estado'] == 3 ? 'selected' : '') . ">Cancelada</option>";
        $retorno .= "</select>";

        $retorno .= "<div style='margin-top:15px; display:flex; justify-content:space-between; align-items:center;'>";
        $retorno .= "  <a href='?opcion=maitre' class='BTN_SM' style='background:#64748b; color:white;'>Cancelar Edición</a>";
        $retorno .= "  <button type='submit' class='BTN_SM BTN_ALERTA'>Guardar Modificaciones</button>";
        $retorno .= "</div>";
        $retorno .= "</form></div>";
    } else {
        $retorno .= "<div class='FORM_CARD'>";
        $retorno .= "<h3>Registrar Nueva Reservación</h3>";
        $retorno .= "<form method='POST'>";
        $retorno .= "<input type='hidden' name='accion' value='crear_reservacion'/>";

        // Selector cliente
        $retorno .= "<label>Cliente:</label><select name='cliente_id' required>";
        $q_cli = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cliente' ORDER BY u.nombre;";
        $res_cli = procesar_query($q_cli, $conn);
        foreach ($res_cli->datos as $cli) {
            $retorno .= "<option value='" . $cli['id'] . "'>" . htmlspecialchars($cli['nombre']) . "</option>";
        }
        $retorno .= "</select>";

        $retorno .= "<label>Cantidad de Comensales:</label><input type='number' name='cantidad' min='1' max='20' value='2' required/>";

        // Selector mesa
        $retorno .= "<label>Mesa asignada:</label><select name='mesa_id' required>";
        $q_mes = "SELECT id, sillas FROM mesas ORDER BY id;";
        $res_mes = procesar_query($q_mes, $conn);
        foreach ($res_mes->datos as $m) {
            $retorno .= "<option value='" . $m['id'] . "'>Mesa " . $m['id'] . " (" . $m['sillas'] . " sillas)</option>";
        }
        $retorno .= "</select>";

        $retorno .= "<label>Fecha:</label><input type='date' name='fecha' value='" . date('Y-m-d') . "' required/>";
        $retorno .= "<label>Hora de Inicio:</label><input type='time' name='hora' value='19:00' required/>";
        $retorno .= "<label>Duración estimada (máx 2 horas según regla):</label><select name='duracion_minutos'>";
        $retorno .= "<option value='60'>1 hora (60 min)</option><option value='90' selected>1 hora y media (90 min)</option><option value='120'>2 horas (120 min)</option>";
        $retorno .= "</select>";

        $retorno .= "<div style='margin-top:15px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>Confirmar Reservación</button></div>";
        $retorno .= "</form></div>";
    }

    // LISTADO GENERAL DE RESERVACIONES
    $retorno .= "<h3>Historial Reciente de Reservaciones</h3>";
    $q_hist = "
        SELECT r.id, u.nombre AS cliente, h.mesa_id, m.sillas, r.cantidad, h.inicio, h.duracion, r.estado
        FROM reservaciones r
        JOIN usuarios u ON r.cliente_id = u.id
        LEFT JOIN horarios h ON h.reservacion_id = r.id
        LEFT JOIN mesas m ON h.mesa_id = m.id
        ORDER BY h.inicio DESC NULLS LAST LIMIT 15;
    ";
    $res_h = procesar_query($q_hist, $conn);
    $retorno .= "<table class='TABLA_GESTION'><tr><th>ID</th><th>Cliente</th><th>Mesa</th><th>Comensales</th><th>Fecha y Hora</th><th>Duración</th><th>Estado</th><th>Acción</th></tr>";
    foreach ($res_h->datos as $row) {
        $st = "";
        switch ($row['estado']) {
            case 0: $st = "<span class='BADGE BADGE_SOLICITADO'>Reservada</span>"; break;
            case 1: $st = "<span class='BADGE BADGE_LISTO'>Ocupada</span>"; break;
            case 2: $st = "<span class='BADGE BADGE_ENTREGADO'>Liberada</span>"; break;
            case 3: $st = "<span class='BADGE BADGE_CANCELADO'>Cancelada</span>"; break;
        }
        $retorno .= "<tr>"
                 . "<td>#" . $row['id'] . "</td>"
                 . "<td>" . htmlspecialchars($row['cliente']) . "</td>"
                 . "<td>Mesa " . $row['mesa_id'] . " (" . $row['sillas'] . " sillas)</td>"
                 . "<td>" . $row['cantidad'] . "</td>"
                 . "<td>" . $row['inicio'] . "</td>"
                 . "<td>" . $row['duracion'] . "</td>"
                 . "<td>$st</td>"
                 . "<td><a href='?opcion=maitre&edit_reserva_id=" . $row['id'] . "' class='BTN_SM BTN_ALERTA'>Modificar</a></td>"
                 . "</tr>";
    }
    $retorno .= "</table>";

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief ROL ADMINISTRADOR: Gestión de mesas, menú, personal y reportes.
 */
function fn_administrador($conn)
{
    $retorno = fn_boton_menu_principal();
    $sub = isset($_REQUEST['sub']) ? $_REQUEST['sub'] : 'mesas';

    $retorno .= "<h2>Módulo de Administrador</h2>";
    $retorno .= "<div class='SUBNAV'>"
             . "<a href='?opcion=administrador&sub=mesas' class='" . ($sub == 'mesas' ? 'ACTIVO' : '') . "'>Gestión Mesas</a>"
             . "<a href='?opcion=administrador&sub=menu' class='" . ($sub == 'menu' ? 'ACTIVO' : '') . "'>Gestión Menú</a>"
             . "<a href='?opcion=administrador&sub=empleados' class='" . ($sub == 'empleados' ? 'ACTIVO' : '') . "'>Gestión Empleados</a>"
             . "<a href='?opcion=reportes' class=''>Reportes y Estadísticas</a>"
             . "</div>";

    $mensaje = "";

    // GESTIÓN DE MESAS
    if ($sub == 'mesas') {
        if (isset($_POST['accion']) && $_POST['accion'] == 'crear_mesa') {
            $sillas = intval($_POST['sillas']);
            if ($sillas > 0) {
                if (@pg_query($conn, "INSERT INTO mesas (sillas) VALUES ($sillas);")) {
                    $mensaje = "<div class='ALERTA_SUCCESS'>Mesa agregada correctamente.</div>";
                }
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] == 'editar_mesa') {
            $mesa_id = intval($_POST['mesa_id']);
            $sillas = intval($_POST['sillas']);
            if ($sillas > 0) {
                if (@pg_query($conn, "UPDATE mesas SET sillas = $sillas WHERE id = $mesa_id;")) {
                    $mensaje = "<div class='ALERTA_SUCCESS'>Mesa #$mesa_id actualizada a $sillas asientos correctamente.</div>";
                } else {
                    $mensaje = "<div class='ALERTA_DANGER'>Error al actualizar la mesa: " . htmlspecialchars(pg_last_error($conn)) . "</div>";
                }
            } else {
                $mensaje = "<div class='ALERTA_DANGER'>El número de asientos debe ser mayor a 0.</div>";
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] == 'eliminar_mesa') {
            $mesa_id = intval($_POST['mesa_id']);
            if (@pg_query($conn, "DELETE FROM mesas WHERE id = $mesa_id;")) {
                $mensaje = "<div class='ALERTA_SUCCESS'>Mesa #$mesa_id eliminada.</div>";
            } else {
                $mensaje = "<div class='ALERTA_DANGER'>No se puede eliminar la mesa: tiene reservas asociadas.</div>";
            }
        }

        $retorno .= $mensaje;

        // CONSULTA 6: Cupo total del restaurante
        $q_cupo = "SELECT COUNT(id) AS total_mesas, SUM(sillas) AS cupo_total, ROUND(AVG(sillas),1) AS promedio FROM mesas;";
        $res_c = procesar_query($q_cupo, $conn);
        $total_mesas = $res_c->datos[0]['total_mesas'];
        $cupo_total = $res_c->datos[0]['cupo_total'];
        $promedio = $res_c->datos[0]['promedio'];

        $retorno .= "<div class='ALERTA_INFO' style='text-align:center;'>"
                 . "<strong>Capacidad Total del Restaurante:</strong> $cupo_total asientos sumados en $total_mesas mesas (Promedio: $promedio sillas/mesa)"
                 . "</div>";

        // FORMULARIO: REGISTRAR O MODIFICAR MESA
        $edit_mesa_id = isset($_GET['edit_mesa_id']) ? intval($_GET['edit_mesa_id']) : 0;
        $datos_mesa = null;
        if ($edit_mesa_id > 0) {
            $res_em = procesar_query("SELECT id, sillas FROM mesas WHERE id = $edit_mesa_id;", $conn);
            if ($res_em->cantidad > 0) {
                $datos_mesa = $res_em->datos[0];
            }
        }

        if ($datos_mesa) {
            $retorno .= "<div class='FORM_CARD' style='border: 2px solid #d97706;'>";
            $retorno .= "<h3>Modificar Mesa #" . $datos_mesa['id'] . "</h3>";
            $retorno .= "<form method='POST'>";
            $retorno .= "<input type='hidden' name='accion' value='editar_mesa'/>";
            $retorno .= "<input type='hidden' name='mesa_id' value='" . $datos_mesa['id'] . "'/>";
            $retorno .= "<label>Identificador (ID):</label><input type='text' value='Mesa #" . $datos_mesa['id'] . "' disabled/>";
            $retorno .= "<label>Número de Asientos (Sillas):</label><input type='number' name='sillas' min='1' max='20' value='" . $datos_mesa['sillas'] . "' required/>";
            $retorno .= "<div style='margin-top:15px; display:flex; justify-content:space-between; align-items:center;'>";
            $retorno .= "  <a href='?opcion=administrador&sub=mesas' class='BTN_SM' style='background:#64748b; color:white;'>Cancelar Edición</a>";
            $retorno .= "  <button type='submit' class='BTN_SM BTN_ALERTA'>Guardar Cambios</button>";
            $retorno .= "</div>";
            $retorno .= "</form></div>";
        } else {
            $retorno .= "<div class='FORM_CARD'>";
            $retorno .= "<h3>Agregar Nueva Mesa</h3>";
            $retorno .= "<form method='POST'>";
            $retorno .= "<input type='hidden' name='accion' value='crear_mesa'/>";
            $retorno .= "<label>Número de Asientos (Sillas):</label><input type='number' name='sillas' min='1' max='20' value='4' required/>";
            $retorno .= "<div style='margin-top:10px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>Agregar Mesa</button></div>";
            $retorno .= "</form></div>";
        }

        $q_m = "SELECT id, sillas FROM mesas ORDER BY id;";
        $res_m = procesar_query($q_m, $conn);
        $retorno .= "<h3>Listado de Mesas</h3>";
        $retorno .= "<table class='TABLA_GESTION'><tr><th>Mesa ID</th><th>Capacidad (Sillas)</th><th>Acción</th></tr>";
        foreach ($res_m->datos as $m) {
            $retorno .= "<tr>"
                     . "<td>#" . $m['id'] . "</td>"
                     . "<td>" . $m['sillas'] . " sillas</td>"
                     . "<td>"
                     . "<a href='?opcion=administrador&sub=mesas&edit_mesa_id=" . $m['id'] . "' class='BTN_SM BTN_ALERTA'>Editar</a> "
                     . "<form method='POST' style='display:inline;' onSubmit=\"return confirm('¿Eliminar mesa #" . $m['id'] . "?');\"><input type='hidden' name='accion' value='eliminar_mesa'/><input type='hidden' name='mesa_id' value='" . $m['id'] . "'/><button type='submit' class='BTN_SM BTN_PELIGRO'>Eliminar</button></form>"
                     . "</td>"
                     . "</tr>";
        }
        $retorno .= "</table>";
    }

    // GESTIÓN DE MENÚ
    elseif ($sub == 'menu') {
        if (isset($_POST['accion']) && $_POST['accion'] == 'crear_plato') {
            $nombre = pg_escape_string($conn, $_POST['nombre']);
            $tipo_id = intval($_POST['tipo_id']);
            $descripcion = pg_escape_string($conn, $_POST['descripcion']);
            $tiempo = intval($_POST['tiempo_minutos']) . " minutes";
            $precio = floatval($_POST['precio']);

            $q_ins = "INSERT INTO platos (tipo_id, nombre, descripcion, tiempo, precio) VALUES ($tipo_id, '$nombre', '$descripcion', INTERVAL '$tiempo', $precio);";
            if (@pg_query($conn, $q_ins)) {
                $mensaje = "<div class='ALERTA_SUCCESS'>Plato '$nombre' agregado exitosamente al menú.</div>";
            } else {
                $mensaje = "<div class='ALERTA_DANGER'>Error al agregar plato: " . htmlspecialchars(pg_last_error($conn)) . "</div>";
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] == 'editar_plato') {
            $plato_id = intval($_POST['plato_id']);
            $nombre = pg_escape_string($conn, trim($_POST['nombre']));
            $tipo_id = intval($_POST['tipo_id']);
            $descripcion = pg_escape_string($conn, trim($_POST['descripcion']));
            $tiempo = intval($_POST['tiempo_minutos']) . " minutes";
            $precio = floatval($_POST['precio']);

            $q_upd = "UPDATE platos SET tipo_id = $tipo_id, nombre = '$nombre', descripcion = '$descripcion', tiempo = INTERVAL '$tiempo', precio = $precio WHERE id = $plato_id;";
            if (@pg_query($conn, $q_upd)) {
                $mensaje = "<div class='ALERTA_SUCCESS'>Plato '$nombre' (#$plato_id) actualizado exitosamente.</div>";
            } else {
                $mensaje = "<div class='ALERTA_DANGER'>Error al actualizar plato: " . htmlspecialchars(pg_last_error($conn)) . "</div>";
            }
        } elseif (isset($_POST['accion']) && $_POST['accion'] == 'eliminar_plato') {
            $plato_id = intval($_POST['plato_id']);

            // Verificar si el plato tiene pedidos/órdenes asociadas
            $q_chk = "SELECT COUNT(*) AS total FROM ordenes WHERE plato_id = $plato_id;";
            $res_chk = procesar_query($q_chk, $conn);
            if ($res_chk->datos[0]['total'] > 0) {
                $mensaje = "<div class='ALERTA_DANGER'>No se puede eliminar el plato #" . $plato_id . ": Tiene " . $res_chk->datos[0]['total'] . " orden(es) registrada(s) en el historial de pedidos.</div>";
            } else {
                @pg_query($conn, "BEGIN;");
                @pg_query($conn, "DELETE FROM especialidades WHERE plato_id = $plato_id;");
                $res_del = @pg_query($conn, "DELETE FROM platos WHERE id = $plato_id;");
                if ($res_del) {
                    @pg_query($conn, "COMMIT;");
                    $mensaje = "<div class='ALERTA_SUCCESS'>Plato #" . $plato_id . " eliminado exitosamente del menú.</div>";
                } else {
                    $err = pg_last_error($conn);
                    @pg_query($conn, "ROLLBACK;");
                    $mensaje = "<div class='ALERTA_DANGER'>Error al eliminar plato: " . htmlspecialchars($err) . "</div>";
                }
            }
        }

        $retorno .= $mensaje;

        // FORMULARIO: REGISTRAR O MODIFICAR PLATO
        $edit_plato_id = isset($_GET['edit_plato_id']) ? intval($_GET['edit_plato_id']) : 0;
        $datos_plato = null;
        if ($edit_plato_id > 0) {
            $res_ep = procesar_query("SELECT id, tipo_id, nombre, descripcion, ROUND(EXTRACT(EPOCH FROM tiempo)/60) AS tiempo_minutos, precio FROM platos WHERE id = $edit_plato_id;", $conn);
            if ($res_ep->cantidad > 0) {
                $datos_plato = $res_ep->datos[0];
            }
        }

        if ($datos_plato) {
            $retorno .= "<div class='FORM_CARD' style='border: 2px solid #d97706;'>";
            $retorno .= "<h3>Modificar Ítem del Menú (#" . $datos_plato['id'] . ")</h3>";
            $retorno .= "<form method='POST'>";
            $retorno .= "<input type='hidden' name='sub' value='menu'/>";
            $retorno .= "<input type='hidden' name='accion' value='editar_plato'/>";
            $retorno .= "<input type='hidden' name='plato_id' value='" . $datos_plato['id'] . "'/>";
            $retorno .= "<label>Nombre del Plato:</label><input type='text' name='nombre' value='" . htmlspecialchars($datos_plato['nombre']) . "' required/>";
            $retorno .= "<label>Categoría:</label><select name='tipo_id' required>";
            $q_t = "SELECT id, nombre FROM tipos ORDER BY id;";
            $res_t = procesar_query($q_t, $conn);
            foreach ($res_t->datos as $t) {
                $sel = ($t['id'] == $datos_plato['tipo_id']) ? "selected" : "";
                $retorno .= "<option value='" . $t['id'] . "' $sel>" . htmlspecialchars($t['nombre']) . "</option>";
            }
            $retorno .= "</select>";
            $retorno .= "<label>Descripción:</label><textarea name='descripcion' rows='2'>" . htmlspecialchars($datos_plato['descripcion']) . "</textarea>";
            $retorno .= "<label>Tiempo de Preparación (Minutos):</label><input type='number' name='tiempo_minutos' min='1' max='180' value='" . $datos_plato['tiempo_minutos'] . "' required/>";
            $retorno .= "<label>Precio ($ COP):</label><input type='number' name='precio' min='0' step='100' value='" . $datos_plato['precio'] . "' required/>";
            $retorno .= "<div style='margin-top:15px; display:flex; justify-content:space-between; align-items:center;'>";
            $retorno .= "  <a href='?opcion=administrador&sub=menu' class='BTN_SM' style='background:#64748b; color:white;'>Cancelar Edición</a>";
            $retorno .= "  <button type='submit' class='BTN_SM BTN_ALERTA'>Guardar Cambios</button>";
            $retorno .= "</div>";
            $retorno .= "</form></div>";
        } else {
            $retorno .= "<div class='FORM_CARD'>";
            $retorno .= "<h3>Agregar Nuevo Ítem al Menú</h3>";
            $retorno .= "<form method='POST'>";
            $retorno .= "<input type='hidden' name='sub' value='menu'/>";
            $retorno .= "<input type='hidden' name='accion' value='crear_plato'/>";
            $retorno .= "<label>Nombre del Plato:</label><input type='text' name='nombre' required/>";
            $retorno .= "<label>Categoría:</label><select name='tipo_id' required>";
            $q_t = "SELECT id, nombre FROM tipos ORDER BY id;";
            $res_t = procesar_query($q_t, $conn);
            foreach ($res_t->datos as $t) {
                $retorno .= "<option value='" . $t['id'] . "'>" . htmlspecialchars($t['nombre']) . "</option>";
            }
            $retorno .= "</select>";
            $retorno .= "<label>Descripción:</label><textarea name='descripcion' rows='2'></textarea>";
            $retorno .= "<label>Tiempo de Preparación (Minutos):</label><input type='number' name='tiempo_minutos' min='1' max='180' value='20' required/>";
            $retorno .= "<label>Precio ($ COP):</label><input type='number' name='precio' min='0' step='100' value='15000' required/>";
            $retorno .= "<div style='margin-top:10px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>Guardar Plato</button></div>";
            $retorno .= "</form></div>";
        }

        $q_pl = "SELECT p.id, p.nombre, t.nombre AS tipo, p.descripcion, p.tiempo, p.precio FROM platos p JOIN tipos t ON p.tipo_id = t.id ORDER BY t.id, p.nombre;";
        $res_pl = procesar_query($q_pl, $conn);
        $retorno .= "<h3>Carta del Menú</h3>";
        $retorno .= "<table class='TABLA_GESTION'><tr><th>ID</th><th>Categoría</th><th>Nombre</th><th>Descripción</th><th>Tiempo</th><th>Precio</th><th>Acciones</th></tr>";
        foreach ($res_pl->datos as $pl) {
            $retorno .= "<tr>"
                     . "<td>#" . $pl['id'] . "</td>"
                     . "<td>" . htmlspecialchars($pl['tipo']) . "</td>"
                     . "<td><strong>" . htmlspecialchars($pl['nombre']) . "</strong></td>"
                     . "<td>" . htmlspecialchars($pl['descripcion']) . "</td>"
                     . "<td>" . $pl['tiempo'] . "</td>"
                     . "<td>$" . number_format($pl['precio'], 0, ',', '.') . "</td>"
                     . "<td>"
                     . "<a href='?opcion=administrador&sub=menu&edit_plato_id=" . $pl['id'] . "' class='BTN_SM BTN_ALERTA'>Editar</a> "
                     . "<form method='POST' style='display:inline;' onSubmit=\"return confirm('¿Eliminar el plato " . htmlspecialchars(addslashes($pl['nombre'])) . "?');\"><input type='hidden' name='sub' value='menu'/><input type='hidden' name='accion' value='eliminar_plato'/><input type='hidden' name='plato_id' value='" . $pl['id'] . "'/><button type='submit' class='BTN_SM BTN_PELIGRO'>Eliminar</button></form>"
                     . "</td>"
                     . "</tr>";
        }
        $retorno .= "</table>";
    }

    // GESTIÓN DE EMPLEADOS
    elseif ($sub == 'empleados') {
        if (isset($_POST['accion'])) {
            if ($_POST['accion'] == 'crear_empleado') {
                $nombre = pg_escape_string($conn, $_POST['nombre']);
                $clave = pg_escape_string($conn, $_POST['clave']);
                $rol_id = intval($_POST['rol_id']);

                $q_u = "INSERT INTO usuarios (nombre, clave, fecha_clave) VALUES ('$nombre', digest('$clave', 'sha256'), NOW()) RETURNING id;";
                $res_u = @pg_query($conn, $q_u);
                if ($res_u) {
                    $u_row = pg_fetch_assoc($res_u);
                    $new_uid = $u_row['id'];
                    @pg_query($conn, "INSERT INTO actuaciones (rol_id, usuario_id) VALUES ($rol_id, $new_uid);");
                    $mensaje = "<div class='ALERTA_SUCCESS'>Empleado '$nombre' registrado con éxito.</div>";
                }
            } elseif ($_POST['accion'] == 'asignar_especialidad') {
                $cocinero_id = intval($_POST['cocinero_id']);
                $plato_id = intval($_POST['plato_id']);
                // Trigger trg_validar_cocinero_especialidad verifica rol de cocinero
                $q_esp = "INSERT INTO especialidades (cocinero_id, plato_id) VALUES ($cocinero_id, $plato_id);";
                if (@pg_query($conn, $q_esp)) {
                    $mensaje = "<div class='ALERTA_SUCCESS'>Especialidad asignada correctamente al cocinero.</div>";
                } else {
                    $mensaje = "<div class='ALERTA_DANGER'>Error al asignar (Trigger Cocinero): " . htmlspecialchars(pg_last_error($conn)) . "</div>";
                }
            }
        }
        $retorno .= $mensaje;

        $retorno .= "<div style='display:flex; flex-wrap:wrap; gap:20px; justify-content:center;'>";
        
        // Form 1: Nuevo empleado
        $retorno .= "<div class='FORM_CARD' style='flex:1; min-width:300px;'>";
        $retorno .= "<h3>Registrar Empleado</h3><form method='POST'>";
        $retorno .= "<input type='hidden' name='accion' value='crear_empleado'/>";
        $retorno .= "<label>Nombre Completo:</label><input type='text' name='nombre' required/>";
        $retorno .= "<label>Contraseña:</label><input type='password' name='clave' required/>";
        $retorno .= "<label>Rol a Asignar:</label><select name='rol_id' required>";
        $q_r = "SELECT id, nombre FROM roles WHERE nombre IN ('maitre', 'mesero', 'cocinero', 'administrador') ORDER BY id;";
        $res_r = procesar_query($q_r, $conn);
        foreach ($res_r->datos as $r) {
            $retorno .= "<option value='" . $r['id'] . "'>" . ucfirst($r['nombre']) . "</option>";
        }
        $retorno .= "</select>";
        $retorno .= "<div style='margin-top:10px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>Registrar</button></div>";
        $retorno .= "</form></div>";

        // Form 2: Asignar especialidad
        $retorno .= "<div class='FORM_CARD' style='flex:1; min-width:300px;'>";
        $retorno .= "<h3>Asignar Especialidad a Cocinero</h3><form method='POST'>";
        $retorno .= "<input type='hidden' name='accion' value='asignar_especialidad'/>";
        $retorno .= "<label>Cocinero:</label><select name='cocinero_id' required>";
        $q_c = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cocinero' ORDER BY u.nombre;";
        $res_c = procesar_query($q_c, $conn);
        foreach ($res_c->datos as $c) {
            $retorno .= "<option value='" . $c['id'] . "'>" . htmlspecialchars($c['nombre']) . "</option>";
        }
        $retorno .= "</select>";
        $retorno .= "<label>Plato del Menú:</label><select name='plato_id' required>";
        $q_p = "SELECT id, nombre FROM platos ORDER BY nombre;";
        $res_p = procesar_query($q_p, $conn);
        foreach ($res_p->datos as $p) {
            $retorno .= "<option value='" . $p['id'] . "'>" . htmlspecialchars($p['nombre']) . "</option>";
        }
        $retorno .= "</select>";
        $retorno .= "<div style='margin-top:10px; text-align:right;'><button type='submit' class='BTN_SM BTN_PRIMARIO'>Asignar</button></div>";
        $retorno .= "</form></div>";
        $retorno .= "</div>";

        // Lista de empleados y sus roles
        $retorno .= "<h3>Personal del Restaurante</h3>";
        $q_emp = "
            SELECT u.id, u.nombre, r.nombre AS rol,
                   COALESCE(STRING_AGG(p.nombre, ', '), 'N/A') AS especialidades
            FROM usuarios u
            JOIN actuaciones a ON u.id = a.usuario_id
            JOIN roles r ON a.rol_id = r.id
            LEFT JOIN especialidades e ON u.id = e.cocinero_id
            LEFT JOIN platos p ON e.plato_id = p.id
            GROUP BY u.id, u.nombre, r.nombre
            ORDER BY r.nombre, u.nombre;
        ";
        $res_emp = procesar_query($q_emp, $conn);
        $retorno .= "<table class='TABLA_GESTION'><tr><th>ID</th><th>Nombre</th><th>Rol</th><th>Especialidades (Cocina)</th></tr>";
        foreach ($res_emp->datos as $emp) {
            $retorno .= "<tr>"
                     . "<td>#" . $emp['id'] . "</td>"
                     . "<td><strong>" . htmlspecialchars($emp['nombre']) . "</strong></td>"
                     . "<td><span class='BADGE BADGE_ENTREGADO'>" . ucfirst($emp['rol']) . "</span></td>"
                     . "<td>" . htmlspecialchars($emp['especialidades']) . "</td>"
                     . "</tr>";
        }
        $retorno .= "</table>";
    }

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief CASO DE USO 6: REPORTES Y ESTADÍSTICAS DEL RESTAURANTE
 * Permite alternar la visualización por períodos (día, semana, mes) y filtrar por período específico.
 */
function fn_reportes($conn)
{
    $retorno = fn_boton_menu_principal();
    $retorno .= "<h2>Reportes y Estadísticas del Restaurante</h2>";

    // 1. SELECTOR DE PERÍODO (DÍA / SEMANA / MES)
    $periodo = isset($_REQUEST['periodo']) ? strtolower(trim($_REQUEST['periodo'])) : 'dia';
    if (!in_array($periodo, ['dia', 'semana', 'mes'])) {
        $periodo = 'dia';
    }

    $filtro = isset($_REQUEST['filtro']) ? trim($_REQUEST['filtro']) : '';

    // Validar formato del filtro según el período seleccionado
    if ($periodo == 'dia' && $filtro !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtro)) {
        $filtro = '';
    } elseif ($periodo == 'semana' && $filtro !== '' && !preg_match('/^\d{4}-W\d{2}$/', $filtro)) {
        $filtro = '';
    } elseif ($periodo == 'mes' && $filtro !== '' && !preg_match('/^\d{4}-\d{2}$/', $filtro)) {
        $filtro = '';
    }

    // Pestañas de navegación rápida por período
    $retorno .= "<div class='SUBNAV'>"
             . "<a href='?opcion=reportes&periodo=dia' class='" . ($periodo == 'dia' ? 'ACTIVO' : '') . "'>&#128197; Por Día (Diario)</a>"
             . "<a href='?opcion=reportes&periodo=semana' class='" . ($periodo == 'semana' ? 'ACTIVO' : '') . "'>&#128197; Por Semana (Semanal)</a>"
             . "<a href='?opcion=reportes&periodo=mes' class='" . ($periodo == 'mes' ? 'ACTIVO' : '') . "'>&#128197; Por Mes (Mensual)</a>"
             . "</div>";

    // Consultar opciones dinámicas para el desplegable de filtro según el período
    $opciones_filtro = array();
    if ($periodo == 'dia') {
        $q_opts = "
            SELECT DISTINCT DATE(solicitado)::text AS val, DATE(solicitado)::text AS etiqueta
            FROM ordenes
            UNION
            SELECT DISTINCT DATE(inicio)::text AS val, DATE(inicio)::text AS etiqueta
            FROM horarios
            ORDER BY val DESC;
        ";
        $res_opts = procesar_query($q_opts, $conn);
        $opciones_filtro = $res_opts->datos;
        $label_filtro = "Filtrar por Día:";
        $placeholder_filtro = "-- Todos los días registrados --";
    } elseif ($periodo == 'semana') {
        $q_opts = "
            SELECT DISTINCT TO_CHAR(solicitado, 'IYYY-\"W\"IW') AS val,
                   TO_CHAR(solicitado, 'IYYY-\"W\"IW') || ' (' || DATE_TRUNC('week', solicitado)::DATE || ' al ' || (DATE_TRUNC('week', solicitado) + INTERVAL '6 days')::DATE || ')' AS etiqueta,
                   DATE_TRUNC('week', solicitado)::DATE AS orden
            FROM ordenes
            UNION
            SELECT DISTINCT TO_CHAR(inicio, 'IYYY-\"W\"IW') AS val,
                   TO_CHAR(inicio, 'IYYY-\"W\"IW') || ' (' || DATE_TRUNC('week', inicio)::DATE || ' al ' || (DATE_TRUNC('week', inicio) + INTERVAL '6 days')::DATE || ')' AS etiqueta,
                   DATE_TRUNC('week', inicio)::DATE AS orden
            FROM horarios
            ORDER BY orden DESC;
        ";
        $res_opts = procesar_query($q_opts, $conn);
        $opciones_filtro = $res_opts->datos;
        $label_filtro = "Filtrar por Semana:";
        $placeholder_filtro = "-- Todas las semanas registradas --";
    } else { // mes
        $q_opts = "
            SELECT DISTINCT TO_CHAR(solicitado, 'YYYY-MM') AS val,
                   TO_CHAR(solicitado, 'YYYY-MM') AS etiqueta
            FROM ordenes
            UNION
            SELECT DISTINCT TO_CHAR(inicio, 'YYYY-MM') AS val,
                   TO_CHAR(inicio, 'YYYY-MM') AS etiqueta
            FROM horarios
            ORDER BY val DESC;
        ";
        $res_opts = procesar_query($q_opts, $conn);
        $opciones_filtro = $res_opts->datos;
        $label_filtro = "Filtrar por Mes:";
        $placeholder_filtro = "-- Todos los meses registrados --";
    }

    // Barra interactiva de selectores (Selector de tipo de período + Selector de fecha específica)
    $retorno .= "<div class='SELECTOR_BAR'>";
    $retorno .= "<form method='GET' style='display:flex; flex-wrap:wrap; align-items:center; justify-content:center; gap:12px; margin:0;'>";
    $retorno .= "<input type='hidden' name='opcion' value='reportes'/>";

    $retorno .= "<div class='SELECTOR_ITEM'>";
    $retorno .= "<label for='periodo_sel'>&#9201; Agrupación:</label>";
    $retorno .= "<select id='periodo_sel' name='periodo' onChange='this.form.submit()'>";
    $retorno .= "<option value='dia'" . ($periodo == 'dia' ? ' selected' : '') . ">Día (Diario)</option>";
    $retorno .= "<option value='semana'" . ($periodo == 'semana' ? ' selected' : '') . ">Semana (Semanal)</option>";
    $retorno .= "<option value='mes'" . ($periodo == 'mes' ? ' selected' : '') . ">Mes (Mensual)</option>";
    $retorno .= "</select>";
    $retorno .= "</div>";

    $retorno .= "<div class='SELECTOR_ITEM'>";
    $retorno .= "<label for='filtro_sel'>$label_filtro</label>";
    $retorno .= "<select id='filtro_sel' name='filtro' onChange='this.form.submit()'>";
    $retorno .= "<option value=''>$placeholder_filtro</option>";
    foreach ($opciones_filtro as $opt) {
        $sel = ($filtro === $opt['val']) ? ' selected' : '';
        $retorno .= "<option value='" . htmlspecialchars($opt['val']) . "'$sel>" . htmlspecialchars($opt['etiqueta']) . "</option>";
    }
    $retorno .= "</select>";
    $retorno .= "</div>";

    if ($filtro !== '') {
        $retorno .= "<div><a href='?opcion=reportes&periodo=$periodo' class='BTN_SM' style='background:#64748b; color:white;'>Limpiar Filtro</a></div>";
    }

    $retorno .= "</form>";
    $retorno .= "</div>";

    // Cláusulas WHERE sanitizadas para ventas y reservaciones
    $where_ventas = "";
    $where_reservas = "";
    $filtro_escapado = pg_escape_string($conn, $filtro);

    if ($filtro !== '') {
        if ($periodo == 'dia') {
            $where_ventas = "WHERE DATE(o.solicitado) = '$filtro_escapado'";
            $where_reservas = "WHERE DATE(h.inicio) = '$filtro_escapado'";
        } elseif ($periodo == 'semana') {
            $where_ventas = "WHERE TO_CHAR(o.solicitado, 'IYYY-\"W\"IW') = '$filtro_escapado'";
            $where_reservas = "WHERE TO_CHAR(h.inicio, 'IYYY-\"W\"IW') = '$filtro_escapado'";
        } else { // mes
            $where_ventas = "WHERE TO_CHAR(o.solicitado, 'YYYY-MM') = '$filtro_escapado'";
            $where_reservas = "WHERE TO_CHAR(h.inicio, 'YYYY-MM') = '$filtro_escapado'";
        }
    }

    // TARJETAS RESUMEN DE INDICADORES (KPIs)
    $q_kpi_v = "
        SELECT 
            COUNT(DISTINCT o.pedido_id) AS total_pedidos,
            COALESCE(SUM(o.cantidad), 0) AS total_platos,
            '$' || TO_CHAR(COALESCE(SUM(o.cantidad * p.precio), 0), 'FM999,999,999') AS total_ventas
        FROM ordenes o
        JOIN platos p ON o.plato_id = p.id
        $where_ventas;
    ";
    $res_kv = procesar_query($q_kpi_v, $conn);
    $kv = $res_kv->datos[0];

    $q_kpi_r = "
        SELECT 
            COUNT(r.id) AS total_reservas,
            COALESCE(SUM(r.cantidad), 0) AS total_comensales,
            COUNT(r.id) FILTER (WHERE r.estado = 2) AS atendidas,
            COUNT(r.id) FILTER (WHERE r.estado = 3) AS canceladas,
            COALESCE(ROUND((COUNT(r.id) FILTER (WHERE r.estado = 2) * 100.0) / NULLIF(COUNT(r.id), 0), 1)::text || '%', '0%') AS tasa_exito
        FROM horarios h
        JOIN reservaciones r ON h.reservacion_id = r.id
        $where_reservas;
    ";
    $res_kr = procesar_query($q_kpi_r, $conn);
    $kr = $res_kr->datos[0];

    $retorno .= "<div class='KPI_GRID'>"
             . "  <div class='KPI_CARD'><div class='KPI_VALOR'>" . $kv['total_ventas'] . "</div><div class='KPI_TITULO'>Facturación Ventas</div></div>"
             . "  <div class='KPI_CARD'><div class='KPI_VALOR'>" . $kv['total_pedidos'] . "</div><div class='KPI_TITULO'>Pedidos Atendidos</div></div>"
             . "  <div class='KPI_CARD'><div class='KPI_VALOR'>" . $kv['total_platos'] . "</div><div class='KPI_TITULO'>Platos Servidos</div></div>"
             . "  <div class='KPI_CARD'><div class='KPI_VALOR'>" . $kr['total_reservas'] . "</div><div class='KPI_TITULO'>Reservaciones</div></div>"
             . "  <div class='KPI_CARD'><div class='KPI_VALOR'>" . $kr['tasa_exito'] . "</div><div class='KPI_TITULO'>Tasa Éxito Reservas</div></div>"
             . "</div>";

    // 2. REPORTE DE VENTAS SEGÚN PERÍODO
    if ($periodo == 'dia') {
        $retorno .= "<h3>&#128197; Ventas Totales por Día" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_ventas = "
            SELECT 
                DATE(o.solicitado) AS fecha,
                COUNT(DISTINCT o.pedido_id) AS total_pedidos,
                SUM(o.cantidad) AS total_platos_vendidos,
                '$' || TO_CHAR(SUM(o.cantidad * p.precio), 'FM999,999,999') AS total_facturado,
                '$' || TO_CHAR(ROUND(SUM(o.cantidad * p.precio) / NULLIF(COUNT(DISTINCT o.pedido_id), 0), 0), 'FM999,999,999') AS ticket_promedio
            FROM ordenes o
            JOIN platos p ON o.plato_id = p.id
            $where_ventas
            GROUP BY DATE(o.solicitado)
            ORDER BY fecha DESC;
        ";
        $res_v = procesar_query($q_ventas, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Fecha</th><th>Pedidos Atendidos</th><th>Platos Vendidos</th><th>Total Facturado</th><th>Ticket Promedio</th></tr>";
        if ($res_v->cantidad == 0) {
            $retorno .= "<tr><td colspan='5'>No se encontraron registros de ventas para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_v->datos as $v) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $v['fecha'] . "</strong></td>"
                         . "<td>" . $v['total_pedidos'] . "</td>"
                         . "<td>" . $v['total_platos_vendidos'] . "</td>"
                         . "<td><strong>" . $v['total_facturado'] . "</strong></td>"
                         . "<td>" . $v['ticket_promedio'] . "</td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";

    } elseif ($periodo == 'semana') {
        $retorno .= "<h3>&#128197; Ventas Totales por Semana" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_ventas = "
            SELECT 
                TO_CHAR(o.solicitado, 'IYYY-\"W\"IW') AS anio_semana,
                DATE_TRUNC('week', o.solicitado)::DATE AS inicio_semana,
                (DATE_TRUNC('week', o.solicitado) + INTERVAL '6 days')::DATE AS fin_semana,
                COUNT(DISTINCT o.pedido_id) AS total_pedidos,
                SUM(o.cantidad) AS platos_vendidos,
                '$' || TO_CHAR(SUM(o.cantidad * p.precio), 'FM999,999,999') AS total_ventas_semana,
                '$' || TO_CHAR(ROUND(SUM(o.cantidad * p.precio) / NULLIF(COUNT(DISTINCT o.pedido_id), 0), 0), 'FM999,999,999') AS ticket_promedio
            FROM ordenes o
            JOIN platos p ON o.plato_id = p.id
            $where_ventas
            GROUP BY TO_CHAR(o.solicitado, 'IYYY-\"W\"IW'), DATE_TRUNC('week', o.solicitado)
            ORDER BY inicio_semana DESC;
        ";
        $res_v = procesar_query($q_ventas, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Semana (ISO)</th><th>Fecha Inicio</th><th>Fecha Fin</th><th>Pedidos Atendidos</th><th>Platos Vendidos</th><th>Total Facturado</th><th>Ticket Promedio</th></tr>";
        if ($res_v->cantidad == 0) {
            $retorno .= "<tr><td colspan='7'>No se encontraron registros de ventas para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_v->datos as $v) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $v['anio_semana'] . "</strong></td>"
                         . "<td>" . $v['inicio_semana'] . "</td>"
                         . "<td>" . $v['fin_semana'] . "</td>"
                         . "<td>" . $v['total_pedidos'] . "</td>"
                         . "<td>" . $v['platos_vendidos'] . "</td>"
                         . "<td><strong>" . $v['total_ventas_semana'] . "</strong></td>"
                         . "<td>" . $v['ticket_promedio'] . "</td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";

    } else { // mes
        $retorno .= "<h3>&#128197; Ventas Totales por Mes con Desglose por Categoría" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_ventas = "
            SELECT 
                TO_CHAR(o.solicitado, 'YYYY-MM') AS periodo_mes,
                COUNT(DISTINCT o.pedido_id) AS total_pedidos,
                SUM(o.cantidad) AS total_unidades,
                '$' || TO_CHAR(COALESCE(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Entrada'), 0), 'FM999,999,999') AS vtas_entradas,
                '$' || TO_CHAR(COALESCE(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Plato fuerte'), 0), 'FM999,999,999') AS vtas_platos_fuertes,
                '$' || TO_CHAR(COALESCE(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Bebida'), 0), 'FM999,999,999') AS vtas_bebidas,
                '$' || TO_CHAR(COALESCE(SUM(o.cantidad * p.precio) FILTER (WHERE t.nombre = 'Postre'), 0), 'FM999,999,999') AS vtas_postres,
                '$' || TO_CHAR(SUM(o.cantidad * p.precio), 'FM999,999,999') AS total_facturado_mes
            FROM ordenes o
            JOIN platos p ON o.plato_id = p.id
            JOIN tipos t ON p.tipo_id = t.id
            $where_ventas
            GROUP BY TO_CHAR(o.solicitado, 'YYYY-MM')
            ORDER BY periodo_mes DESC;
        ";
        $res_v = procesar_query($q_ventas, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Mes</th><th>Pedidos Atendidos</th><th>Total Platos</th><th>Entradas</th><th>Platos Fuertes</th><th>Bebidas</th><th>Postres</th><th>Total Facturado</th></tr>";
        if ($res_v->cantidad == 0) {
            $retorno .= "<tr><td colspan='8'>No se encontraron registros de ventas para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_v->datos as $v) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $v['periodo_mes'] . "</strong></td>"
                         . "<td>" . $v['total_pedidos'] . "</td>"
                         . "<td>" . $v['total_unidades'] . "</td>"
                         . "<td>" . $v['vtas_entradas'] . "</td>"
                         . "<td>" . $v['vtas_platos_fuertes'] . "</td>"
                         . "<td>" . $v['vtas_bebidas'] . "</td>"
                         . "<td>" . $v['vtas_postres'] . "</td>"
                         . "<td><strong>" . $v['total_facturado_mes'] . "</strong></td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";
    }

    // 3. BALANCE DE RESERVACIONES SEGÚN PERÍODO
    if ($periodo == 'dia') {
        $retorno .= "<h3>&#128101; Balance de Reservaciones por Día" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_res = "
            SELECT 
                DATE(h.inicio) AS periodo,
                COUNT(r.id) AS total_reservas,
                SUM(r.cantidad) AS comensales,
                COUNT(r.id) FILTER (WHERE r.estado = 2) AS atendidas_liberadas,
                COUNT(r.id) FILTER (WHERE r.estado = 3) AS canceladas,
                COALESCE(ROUND((COUNT(r.id) FILTER (WHERE r.estado = 2) * 100.0) / NULLIF(COUNT(r.id), 0), 1)::text || '%', '0%') AS tasa_exito
            FROM horarios h
            JOIN reservaciones r ON h.reservacion_id = r.id
            $where_reservas
            GROUP BY DATE(h.inicio)
            ORDER BY periodo DESC;
        ";
        $res_res = procesar_query($q_res, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Fecha</th><th>Total Reservas</th><th>Total Comensales</th><th>Atendidas con Éxito</th><th>Canceladas</th><th>Tasa de Éxito</th></tr>";
        if ($res_res->cantidad == 0) {
            $retorno .= "<tr><td colspan='6'>No se encontraron reservaciones para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_res->datos as $res) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $res['periodo'] . "</strong></td>"
                         . "<td>" . $res['total_reservas'] . "</td>"
                         . "<td>" . $res['comensales'] . "</td>"
                         . "<td>" . $res['atendidas_liberadas'] . "</td>"
                         . "<td>" . $res['canceladas'] . "</td>"
                         . "<td><strong>" . $res['tasa_exito'] . "</strong></td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";

    } elseif ($periodo == 'semana') {
        $retorno .= "<h3>&#128101; Balance de Reservaciones por Semana" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_res = "
            SELECT 
                TO_CHAR(h.inicio, 'IYYY-\"W\"IW') AS anio_semana,
                DATE_TRUNC('week', h.inicio)::DATE AS inicio_semana,
                (DATE_TRUNC('week', h.inicio) + INTERVAL '6 days')::DATE AS fin_semana,
                COUNT(r.id) AS total_reservas,
                SUM(r.cantidad) AS comensales,
                COUNT(r.id) FILTER (WHERE r.estado = 2) AS atendidas_liberadas,
                COUNT(r.id) FILTER (WHERE r.estado = 3) AS canceladas,
                COALESCE(ROUND((COUNT(r.id) FILTER (WHERE r.estado = 2) * 100.0) / NULLIF(COUNT(r.id), 0), 1)::text || '%', '0%') AS tasa_exito
            FROM horarios h
            JOIN reservaciones r ON h.reservacion_id = r.id
            $where_reservas
            GROUP BY TO_CHAR(h.inicio, 'IYYY-\"W\"IW'), DATE_TRUNC('week', h.inicio)
            ORDER BY inicio_semana DESC;
        ";
        $res_res = procesar_query($q_res, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Semana (ISO)</th><th>Fecha Inicio</th><th>Fecha Fin</th><th>Total Reservas</th><th>Total Comensales</th><th>Atendidas con Éxito</th><th>Canceladas</th><th>Tasa de Éxito</th></tr>";
        if ($res_res->cantidad == 0) {
            $retorno .= "<tr><td colspan='8'>No se encontraron reservaciones para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_res->datos as $res) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $res['anio_semana'] . "</strong></td>"
                         . "<td>" . $res['inicio_semana'] . "</td>"
                         . "<td>" . $res['fin_semana'] . "</td>"
                         . "<td>" . $res['total_reservas'] . "</td>"
                         . "<td>" . $res['comensales'] . "</td>"
                         . "<td>" . $res['atendidas_liberadas'] . "</td>"
                         . "<td>" . $res['canceladas'] . "</td>"
                         . "<td><strong>" . $res['tasa_exito'] . "</strong></td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";

    } else { // mes
        $retorno .= "<h3>&#128101; Balance de Reservaciones por Mes" . ($filtro !== '' ? " ($filtro)" : "") . "</h3>";
        $q_res = "
            SELECT 
                TO_CHAR(h.inicio, 'YYYY-MM') AS periodo,
                COUNT(r.id) AS total_reservas,
                SUM(r.cantidad) AS comensales,
                COUNT(r.id) FILTER (WHERE r.estado = 2) AS atendidas_liberadas,
                COUNT(r.id) FILTER (WHERE r.estado = 3) AS canceladas,
                COALESCE(ROUND((COUNT(r.id) FILTER (WHERE r.estado = 2) * 100.0) / NULLIF(COUNT(r.id), 0), 1)::text || '%', '0%') AS tasa_exito
            FROM horarios h
            JOIN reservaciones r ON h.reservacion_id = r.id
            $where_reservas
            GROUP BY TO_CHAR(h.inicio, 'YYYY-MM')
            ORDER BY periodo DESC;
        ";
        $res_res = procesar_query($q_res, $conn);
        $retorno .= "<table class='TABLA_GESTION'>"
                 . "<tr><th>Mes</th><th>Total Reservas</th><th>Total Comensales</th><th>Atendidas con Éxito</th><th>Canceladas</th><th>Tasa de Éxito</th></tr>";
        if ($res_res->cantidad == 0) {
            $retorno .= "<tr><td colspan='6'>No se encontraron reservaciones para el período seleccionado.</td></tr>";
        } else {
            foreach ($res_res->datos as $res) {
                $retorno .= "<tr>"
                         . "<td><strong>" . $res['periodo'] . "</strong></td>"
                         . "<td>" . $res['total_reservas'] . "</td>"
                         . "<td>" . $res['comensales'] . "</td>"
                         . "<td>" . $res['atendidas_liberadas'] . "</td>"
                         . "<td>" . $res['canceladas'] . "</td>"
                         . "<td><strong>" . $res['tasa_exito'] . "</strong></td>"
                         . "</tr>";
            }
        }
        $retorno .= "</table>";
    }

    // 4. RANKING DE PLATOS MÁS SOLICITADOS
    $titulo_rank = "&#127942; Ranking de Platos Más Solicitados";
    if ($filtro !== '') {
        $titulo_rank .= " (Período: $filtro)";
    }
    $retorno .= "<h3>$titulo_rank</h3>";

    $q_rank = "
        SELECT 
            DENSE_RANK() OVER (ORDER BY SUM(o.cantidad) DESC) AS ranking,
            p.nombre AS plato, 
            t.nombre AS categoria,
            SUM(o.cantidad) AS unidades,
            '$' || TO_CHAR(SUM(o.cantidad * p.precio), 'FM999,999,999') AS total_recaudado,
            COALESCE(ROUND((SUM(o.cantidad * p.precio) * 100.0) / NULLIF(SUM(SUM(o.cantidad * p.precio)) OVER (), 0), 2)::text || '%', '0%') AS pct_ventas
        FROM ordenes o
        JOIN platos p ON o.plato_id = p.id
        JOIN tipos t ON p.tipo_id = t.id
        $where_ventas
        GROUP BY p.id, p.nombre, t.nombre, p.precio
        ORDER BY unidades DESC;
    ";
    $res_r = procesar_query($q_rank, $conn);
    $retorno .= "<table class='TABLA_GESTION'>"
             . "<tr><th>Rank</th><th>Plato</th><th>Categoría</th><th>Unidades Vendidas</th><th>Total Facturado</th><th>% Ventas</th></tr>";
    if ($res_r->cantidad == 0) {
        $retorno .= "<tr><td colspan='6'>No hay datos de platos solicitados en el período seleccionado.</td></tr>";
    } else {
        foreach ($res_r->datos as $r) {
            $retorno .= "<tr>"
                     . "<td><strong>#" . $r['ranking'] . "</strong></td>"
                     . "<td>" . htmlspecialchars($r['plato']) . "</td>"
                     . "<td>" . htmlspecialchars($r['categoria']) . "</td>"
                     . "<td>" . $r['unidades'] . "</td>"
                     . "<td>" . $r['total_recaudado'] . "</td>"
                     . "<td>" . $r['pct_ventas'] . "</td>"
                     . "</tr>";
        }
    }
    $retorno .= "</table>";

    return $retorno;
}

/*------------------------------------------------------------------*/
/**
 * @brief SECCIÓN 4.3: HISTORIAL DE CLIENTES
 */
function fn_historial_cliente($conn)
{
    $retorno = fn_boton_menu_principal();
    $retorno .= "<h2>Historial de Clientes</h2>";

    $cliente_id = isset($_GET['cliente_id']) ? intval($_GET['cliente_id']) : 1;

    $q_c = "SELECT u.id, u.nombre FROM usuarios u JOIN actuaciones a ON u.id = a.usuario_id JOIN roles r ON a.rol_id = r.id WHERE r.nombre = 'cliente' ORDER BY u.nombre;";
    $res_c = procesar_query($q_c, $conn);

    $retorno .= "<div style='text-align:center; margin-bottom:15px;'><form method='GET'>";
    $retorno .= "<input type='hidden' name='opcion' value='historial_cliente'/>";
    $retorno .= "<label style='font-weight:bold; margin-right:10px;'>Seleccione Cliente:</label>";
    $retorno .= "<select name='cliente_id' onChange='this.form.submit()' style='padding:6px; font-size:1em; border-radius:5px;'>";
    foreach ($res_c->datos as $c) {
        $sel = ($cliente_id == $c['id']) ? "selected" : "";
        $retorno .= "<option value='" . $c['id'] . "' $sel>" . htmlspecialchars($c['nombre']) . "</option>";
    }
    $retorno .= "</select></form></div>";

    // CONSULTA 11: Historial unificado de reservas y pedidos
    $q_hist = "
        SELECT 'RESERVA' AS tipo, h.inicio::text AS fecha,
               'Mesa ' || h.mesa_id || ' (' || r.cantidad || ' comensales)' AS detalle,
               CASE r.estado WHEN 0 THEN 'Reservada' WHEN 1 THEN 'Ocupada' WHEN 2 THEN 'Liberada' WHEN 3 THEN 'Cancelada' END AS estado,
               '-' AS importe
        FROM reservaciones r
        JOIN horarios h ON h.reservacion_id = r.id
        WHERE r.cliente_id = $cliente_id

        UNION ALL

        SELECT 'PEDIDO' AS tipo, o.solicitado::text AS fecha,
               STRING_AGG(pl.nombre || ' (x' || o.cantidad || ')', ', ') AS detalle,
               'Entregado' AS estado,
               TO_CHAR(SUM(o.cantidad * pl.precio), '$999,999') AS importe
        FROM pedidos p
        JOIN ordenes o ON o.pedido_id = p.id
        JOIN platos pl ON o.plato_id = pl.id
        WHERE p.cliente_id = $cliente_id
        GROUP BY p.id, o.solicitado

        ORDER BY fecha DESC;
    ";
    $res_h = procesar_query($q_hist, $conn);

    $retorno .= "<table class='TABLA_GESTION'><tr><th>Tipo</th><th>Fecha y Hora</th><th>Detalle</th><th>Estado</th><th>Total Facturado</th></tr>";
    if ($res_h->cantidad == 0) {
        $retorno .= "<tr><td colspan='5'>No se encontraron registros para este cliente.</td></tr>";
    } else {
        foreach ($res_h->datos as $h) {
            $badge = ($h['tipo'] == 'RESERVA') ? "<span class='BADGE BADGE_SOLICITADO'>Reserva</span>" : "<span class='BADGE BADGE_ENTREGADO'>Consumo</span>";
            $retorno .= "<tr>"
                     . "<td>$badge</td>"
                     . "<td>" . $h['fecha'] . "</td>"
                     . "<td style='text-align:left;'>" . htmlspecialchars($h['detalle']) . "</td>"
                     . "<td>" . $h['estado'] . "</td>"
                     . "<td><strong>" . $h['importe'] . "</strong></td>"
                     . "</tr>";
        }
    }
    $retorno .= "</table>";

    return $retorno;
}
?>
