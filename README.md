# Proyecto Restaurante — Bases de Datos Administración y Aplicaciones


Sistema para gestionar **mesas, reservaciones, menú, pedidos, preparación y entregas**, garantizando:
- No superar el cupo total del restaurante.
- No cruzar reservaciones en la misma mesa (ocupación máx. 2 horas).
- Cocineros solo preparan platos de su especialidad.
- Meseros registran y entregan pedidos trazables a mesa/cliente.

---

## 1. Stack tecnológico

| Componente | Tecnología |
|---|---|
| BD | PostgreSQL 14+ (extensión `pgcrypto` para `digest(..., 'sha256')`) |
| Backend | PHP (procedural, plantilla del parcial docente) |
| Frontend | HTML/CSS/JS en `public_html/` |
| Servidor | Apache + `php-pgsql` / PHP built-in server (solo dev) |

## 2. Estructura del repositorio

```
/home/estudiante/
├── public_html/
│   ├── index.php                 # Front controller (opcion=...)
│   ├── esqueleto.html            # Plantilla principal (del parcial)
│   ├── etc/parametros.php        # Credenciales BD (NO subir clave real a Git)
│   ├── lib/
│   │   ├── libreria.php          # pg_conectar() + procesar_query() (plantilla docente)
│   │   └── restaurante.php       # Módulos por rol (Mesero, Cocinero, Maitre, Admin, Reportes, Historial)
│   ├── css/estilo.css            # Estilos base + componentes TABLA_GESTION / FORM_CARD / KPI
│   ├── esqueleto_U.html
│   ├── procesador.php / postgres.php
│   └── *.html                    # Páginas de plantilla / pruebas
├── sql/
│   ├── restaurante.sql           # DDL: 12 tablas del ER obligatorio
│   ├── triggers.sql              # 5 triggers de negocio e integridad
│   ├── particionamiento_fechas.sql # horarios PARTITION BY RANGE(inicio) + tablespaces
│   ├── datos_restaurante.sql     # Seed mínimo (roles, usuarios, platos, mesas, 1 reserva/pedido)
│   ├── cargar_datos_reportes.sql # Datos históricos Ago–Oct 2026 para reportes
│   ├── consultas_reportes.sql    # 11 consultas numeradas (casos de uso + reportes)
│   ├── pruebas_triggers.sql      # Casos positivos/negativos por trigger
│   └── pruebas_particiones.sql   # Routing, tableoid y EXPLAIN Partition Pruning
└── README.md                     # Este archivo
```

## 3. Modelo de datos (ER obligatorio)

Se implementaron **exactamente** las 12 tablas del diagrama:

| Tabla | PK / Notas |
|---|---|
| `roles` | `id SERIAL`, `nombre TEXT` (cliente, mesero, cocinero, administrador, maitre) |
| `usuarios` | `id SERIAL`, `nombre`, `clave BYTEA` (hash SHA256), `fecha_clave TIMESTAMP` |
| `actuaciones` | `rol_id → roles`, `usuario_id → usuarios`, UNIQUE(usuario, rol) |
| `tipos` | `id SERIAL`, `nombre` (Entrada, Plato fuerte, Bebida, Postre) |
| `platos` | `tipo_id → tipos`, `nombre`, `descripcion`, `tiempo INTERVAL`, `precio NUMERIC` |
| `mesas` | `id SERIAL`, `sillas INT CHECK > 0` |
| `reservaciones` | `cliente_id → usuarios`, `cantidad > 0`, `estado IN (0 reservada, 1 ocupada, 2 liberada, 3 cancelada)` |
| `horarios` | `mesa_id → mesas`, `reservacion_id → reservaciones`, `inicio TIMESTAMP`, `duracion INTERVAL CHECK (0, 2h]` — **particionada** |
| `pedidos` | `cliente_id → usuarios`, `mesero_id → usuarios` (trigger exige rol mesero) |
| `ordenes` | `plato_id → platos`, `pedido_id → pedidos`, `estado IN (0 solicitado, 1 preparado, 2 entregado)`, `cantidad > 0`, `solicitado TIMESTAMPTZ` |
| `especialidades` | `cocinero_id → usuarios` (rol cocinero), `plato_id → platos`, UNIQUE(cocinero, plato) |
| `preparaciones` | `cocinero_id → usuarios`, `orden_id → ordenes` (trigger exige rol + especialidad) |

### Relación aprogramada “pedido asociado a mesa” (sin modificar ER)

El ER **no** incluye `pedidos.mesa_id`. Para cumplir “pedido asociado a mesa y cliente” se resolvió en el módulo Mesero mediante la cadena obligatoria:

```
pedidos.cliente_id → reservaciones.cliente_id → horarios.mesa_id + fecha
```

En `fn_realizar_pedidos()`:
- El formulario exige `mesa_id` y lo muestra en el título/mensaje de éxito: “Orden #X enviada a cocina! Pedido #Y asociado a la Mesa #Z”.
- La lista “Órdenes en Proceso / Servidas (Mesa #X)” **filtra por mesa** con `EXISTS (SELECT 1 FROM horarios h JOIN reservaciones r ... WHERE h.mesa_id = ... AND r.cliente_id = ped.cliente_id AND DATE(h.inicio) = DATE(o.solicitado))`.
- Se valida `mesa_id > 0`; si es 0 se aborta con error.
- Para clientes walk-in (sin reserva) se mantiene la vista por cliente/fecha.

## 4. Reglas de negocio — triggers

Archivo: `sql/triggers.sql`

1. **Trg Mesero en Pedidos** (`trg_validar_mesero_pedido`) — `pedidos.mesero_id` debe tener rol `mesero`.
2. **Trg Cocinero en Especialidades** (`trg_validar_cocinero_especialidad`) — `especialidades.cocinero_id` debe tener rol `cocinero`.
3. **Trg Cocinero en Preparaciones** (`trg_validar_preparacion_cocinero`) — `preparaciones.cocinero_id` debe tener rol cocinero **y** especialidad en el `plato_id` de la orden.
4. **Trg Horarios** (`trg_validar_horario_reserva`):
   - `duracion` ≤ 2 horas y > 0.
   - `cantidad ≤ sillas` de la mesa.
   - No cruce en la misma mesa: `tsrange(...) && tsrange(...)` excluyendo canceladas (estado=3).
   - No superar cupo total: `SUM(cantidad) solapada + nueva ≤ SUM(sillas)`.
5. **Trg Transición Órdenes** (`trg_validar_transicion_orden`) — `0→2` directo bloqueado; `2→<2` bloqueado.

## 5. Particiones y Tablespaces

Archivo: `sql/particionamiento_fechas.sql`

- Tabla particionada: `horarios PARTITION BY RANGE (inicio)`, PK `(id, inicio)` (requerido por PostgreSQL).
- 3 tablespaces físicos (se deben crear en directorios distintos del disco):
  - `ts_reservas_1` → `horarios_historico` (< 2026-09-01)
  - `ts_reservas_2` → `horarios_actual` (2026-09-01 a 2026-11-01)
  - `ts_reservas_3` → `horarios_futuro` (2026-11-01 a 2027-01-01)
  - `horarios_default` (DEFAULT → 2027+)

Creación de tablespaces (como superusuario/postgres):

```bash
sudo mkdir -p /var/lib/postgresql/tablespaces/ts_reservas_1 \
             /var/lib/postgresql/tablespaces/ts_reservas_2 \
             /var/lib/postgresql/tablespaces/ts_reservas_3
sudo chown postgres:postgres /var/lib/postgresql/tablespaces/ts_reservas_*
psql -U postgres -d restaurante -c "
CREATE TABLESPACE ts_reservas_1 LOCATION '/var/lib/postgresql/tablespaces/ts_reservas_1';
CREATE TABLESPACE ts_reservas_2 LOCATION '/var/lib/postgresql/tablespaces/ts_reservas_2';
CREATE TABLESPACE ts_reservas_3 LOCATION '/var/lib/postgresql/tablespaces/ts_reservas_3';
"
```

Validación: `sql/pruebas_particiones.sql` (insert por rango, `tableoid::regclass` + `pg_tablespace.spcname` y `EXPLAIN` de Partition Pruning).

## 6. Instalación y levantamiento

### 6.1 Prerrequisitos

- PostgreSQL 14+, PHP 7.4+ con `php-pgsql`, Apache (o PHP built-in).
- Extensión: `CREATE EXTENSION IF NOT EXISTS pgcrypto;`
- Usuario/BD: ver `public_html/etc/parametros.php` (por defecto `usuario` / `restaurante`, ajuste con `export PGPASSWORD` y `\c`).

### 6.2 Crear BD e inicializar esquema (orden obligatorio)

```bash
psql -U postgres -c "CREATE DATABASE restaurante OWNER manuel;"
psql -U manuel -d restaurante -c "CREATE EXTENSION IF NOT EXISTS pgcrypto;"

# 1. Tablas ER
psql -U manuel -d restaurante -f sql/restaurante.sql
# 2. Triggers
psql -U manuel -d restaurante -f sql/triggers.sql
# 3. Particiones + tablespaces (crear tablespaces físicos antes)
psql -U manuel -d restaurante -f sql/particionamiento_fechas.sql
# 4. Seed mínimo
psql -U manuel -d restaurante -f sql/datos_restaurante.sql
# 5. Datos para reportes (opcional pero recomendado)
psql -U manuel -d restaurante -f sql/cargar_datos_reportes.sql
```

### 6.3 Servidor web

**Opción A — Apache**

```bash
sudo a2enmod php8.2  # o tu versión
sudo systemctl restart apache2
```

### Credenciales de prueba (seed)

| Usuario | Clave | Rol |
|---|---|---|
| Carlos Perez | `cliente123` | cliente |
| Laura Gomez | `mesero123` | mesero |
| Juan Martinez | `cocinero123` | cocinero |
| Ana Rodriguez | `admin123` | administrador |

> En `cargar_datos_reportes.sql` se añaden Diego Ramirez (maitre), Sofia Lopez (mesero), Andres Torres (cocinero), Mariana Silva / Jorge Castro / Valentina Rojas (cliente), todos con `pass123`/`maitre123` según script.

## 7. Rutas de la aplicación (módulos por rol)

Entradas: `public_html/index.php?opcion=...` (template `esqueleto.html` + `lib/libreria.php` del parcial).

| Ruta | Rol | Caso de Uso |
|---|---|---|
| `?opcion=` | — | Menú principal + alertas Mesero/Maitre |
| `?opcion=desplegar_menu` | — | Ver menú por tipo (original del parcial) |
| `?opcion=realizar_pedidos&mesa_id=X` | Mesero | Pedidos + Entregas (CU-4/5) |
| `?opcion=cocinero&cocinero_id=X` | Cocinero | Comandas por especialidad, `tiempo DESC` |
| `?opcion=maitre` | Maitre | Reservaciones CRUD + notificación 60 min (CU-2) |
| `?opcion=administrador&sub=mesas|menu|empleados` | Admin | Mesas (CU-1), Menú (CU-3), Empleados/Especialidades |
| `?opcion=reportes&periodo=dia|semana|mes&filtro=...` | Admin | Ventas/reservas día/semana/mes + ranking (CU-6) |
| `?opcion=historial_cliente&cliente_id=X` | — | Historial unificado reservas+pedidos |

## 8. Consultas y reportes

Las 11 consultas numeradas están en `sql/consultas_reportes.sql`:

1. Ventas por día
2. Ventas por semana ISO
3. Ventas por mes con desglose por categoría
4. Ranking `DENSE_RANK()` platos más solicitados
5. Reservas totales/canceladas/tasa éxito por mes
6. Cupo total `SUM(sillas)`, `COUNT`, `AVG`
7. Disponibilidad de mesa en rango (excluye solapadas)
8. Comanda Cocinero por `tiempo DESC` (solo especialidades)
9. Notificación Mesero (órdenes `estado=1`)
10. Notificación Maitre (próximas / en curso, ventana 60 min)
11. Historial integral por cliente (`UNION ALL` reservas+pedidos)

La misma lógica se replica en `fn_reportes()` en PHP, con filtros `dia/semana/mes` y KPIs.

## 9. Pruebas

```bash
# Triggers (positivo/negativo)
psql -U manuel -d restaurante -f sql/pruebas_triggers.sql

# Particiones y Partition Pruning
psql -U manuel -d restaurante -f sql/pruebas_particiones.sql

# Conexión PHP/PostgreSQL
php public_html/postgres.php
```

