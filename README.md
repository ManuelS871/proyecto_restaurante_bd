# Proyecto Restaurante — Bases de Datos Administración y Aplicaciones

## 1. Descripción

Proyecto de base de datos para la gestión de un restaurante. El sistema permite administrar mesas, reservaciones, menú, pedidos, preparación y entrega de órdenes, además de generar consultas y reportes.

La solución utiliza PostgreSQL como sistema gestor de bases de datos y una interfaz web desarrollada con PHP, HTML, CSS y JavaScript.

El proyecto implementa las reglas de integridad definidas para el restaurante mediante claves primarias, claves foráneas, restricciones `CHECK`, triggers y particionamiento con tablespaces.

---

## 2. Tecnologías

- PostgreSQL
- PHP
- HTML
- CSS
- JavaScript
- Apache o servidor PHP para pruebas locales

---

## 3. Estructura del proyecto

```text
proyecto_restaurante/
│
├── public_html/
│   ├── index.php
│   ├── esqueleto.html
│   ├── css/
│   │   └── estilo.css
│   ├── etc/
│   │   └── parametros.php
│   └── lib/
│       ├── libreria.php
│       └── restaurante.php
│
├── sql/
│   ├── restaurante.sql
│   ├── datos_restaurante.sql
│   ├── triggers.sql
│   ├── pruebas_triggers.sql
│   ├── particionamiento_fechas.sql
│   ├── pruebas_particiones.sql
│   ├── cargar_datos_reportes.sql
│   └── consultas_reportes.sql
│
├── .gitignore
└── README.md
```

### Función de los principales archivos

| Archivo | Función |
|---|---|
| `public_html/index.php` | Punto de entrada de la aplicación web. |
| `public_html/esqueleto.html` | Plantilla principal de la interfaz. |
| `public_html/lib/libreria.php` | Funciones generales, conexión y procesamiento de consultas. |
| `public_html/lib/restaurante.php` | Funcionalidades del restaurante y módulos por rol. |
| `public_html/etc/parametros.php` | Parámetros de conexión a PostgreSQL. |
| `public_html/css/estilo.css` | Estilos de la interfaz. |
| `sql/restaurante.sql` | Creación de la estructura principal de la base de datos. |
| `sql/datos_restaurante.sql` | Datos iniciales para probar el sistema. |
| `sql/triggers.sql` | Reglas de negocio implementadas mediante triggers. |
| `sql/pruebas_triggers.sql` | Pruebas de los triggers. |
| `sql/particionamiento_fechas.sql` | Configuración del particionamiento y tablespaces. |
| `sql/pruebas_particiones.sql` | Pruebas del particionamiento y distribución de registros. |
| `sql/cargar_datos_reportes.sql` | Carga de datos adicionales para consultas y reportes. |
| `sql/consultas_reportes.sql` | Consultas correspondientes a los casos de uso y reportes. |

---

## 4. Modelo de datos

La base de datos sigue el modelo entidad-relación definido para el proyecto y contiene las siguientes entidades principales:

- `usuarios`
- `roles`
- `actuaciones`
- `mesas`
- `reservaciones`
- `horarios`
- `tipos`
- `platos`
- `especialidades`
- `pedidos`
- `ordenes`
- `preparaciones`

Las relaciones entre estas tablas permiten representar los usuarios y sus roles, las mesas y reservaciones, el menú, los pedidos de los clientes y el proceso de preparación de las órdenes.

---

## 5. Reglas de integridad

El proyecto utiliza diferentes mecanismos de PostgreSQL para garantizar la consistencia de los datos.

### Claves primarias

Cada tabla cuenta con una clave primaria que identifica de manera única sus registros.

### Claves foráneas

Las claves foráneas mantienen las relaciones entre las entidades y evitan referencias a registros inexistentes.

### Restricciones `CHECK`

Se utilizan para validar condiciones propias de los datos, por ejemplo:

- cantidades mayores que cero;
- estados permitidos;
- precios válidos;
- duración válida de una reserva u horario.

### Triggers

Los triggers permiten validar reglas que necesitan consultar otras tablas o comprobar el estado actual de la base de datos.

Entre las reglas implementadas se encuentran las relacionadas con:

- rol de los meseros que registran pedidos;
- rol y especialidad de los cocineros;
- asignación de horarios y reservaciones;
- capacidad de las mesas y del restaurante;
- transición de estados de las órdenes.

Los triggers y sus pruebas se encuentran en:

```text
sql/triggers.sql
sql/pruebas_triggers.sql
```

---

## 6. Particionamiento y tablespaces

El proyecto utiliza PostgreSQL para distribuir físicamente parte de la información mediante **tablespaces** y **particionamiento por rango de fechas**.

La configuración se encuentra en:

```text
sql/particionamiento_fechas.sql
```

El script define los tablespaces y las particiones correspondientes a diferentes rangos de fechas. Los directorios utilizados para los tablespaces deben existir en el servidor y pertenecer al usuario de PostgreSQL.

La ubicación de los directorios puede cambiar dependiendo del sistema operativo o de la instalación de PostgreSQL. Por esta razón, **no se debe asumir una ruta fija**; antes de ejecutar el script se deben revisar las rutas definidas en él y adaptarlas al servidor donde se esté realizando la instalación.

Las pruebas del particionamiento se encuentran en:

```text
sql/pruebas_particiones.sql
```

Estas pruebas permiten comprobar, entre otras cosas, en qué partición termina cada registro y cómo PostgreSQL utiliza las particiones.

---

## 7. Instalación de la base de datos

El orden general recomendado es el siguiente.

### Paso 1. Crear la base de datos

Crear una base de datos PostgreSQL para el proyecto y un usuario con permisos suficientes para trabajar sobre ella.

Ejemplo:

```bash
psql -U postgres
```

Dentro de PostgreSQL:

```sql
CREATE DATABASE restaurante;
```

La creación del usuario y la asignación de permisos dependerán de la configuración del servidor.

### Paso 2. Crear la estructura

Desde la terminal:

```bash
psql -U <usuario> -d restaurante -f sql/restaurante.sql
```

### Paso 3. Cargar los datos iniciales

```bash
psql -U <usuario> -d restaurante -f sql/datos_restaurante.sql
```

### Paso 4. Configurar los triggers

```bash
psql -U <usuario> -d restaurante -f sql/triggers.sql
```

### Paso 5. Configurar tablespaces y particiones

Primero se deben crear los directorios y tablespaces requeridos por el servidor. Después se ejecuta:

```bash
psql -U <usuario> -d restaurante -f sql/particionamiento_fechas.sql
```

### Paso 6. Cargar datos para reportes

Cuando se requieran datos adicionales para las pruebas:

```bash
psql -U <usuario> -d restaurante -f sql/cargar_datos_reportes.sql
```

---

## 8. Configuración de la aplicación web

La aplicación utiliza los parámetros definidos en:

```text
public_html/etc/parametros.php
```

Se deben configurar allí los datos correspondientes al servidor PostgreSQL utilizado:

```text
host
base de datos
usuario
contraseña
```

Las credenciales reales deben mantenerse fuera de repositorios públicos o archivos de entrega cuando sea necesario.

La aplicación utiliza la función de conexión definida en `lib/libreria.php` para comunicarse con PostgreSQL.

---

## 9. Ejecución de la aplicación

La aplicación puede ejecutarse utilizando Apache o un servidor PHP apropiado para el entorno de desarrollo.

Por ejemplo, para una prueba local con el servidor incorporado de PHP:

```bash
php -S localhost:8000 -t public_html
```

Después se puede acceder desde un navegador a:

```text
http://localhost:8000/
```

La configuración exacta dependerá del servidor utilizado.

---

## 10. Funcionalidades principales

### Gestión de mesas

Permite administrar las mesas y su capacidad.

### Gestión de reservaciones

Permite registrar y gestionar reservaciones, asignar horarios y mesas y validar las condiciones de capacidad y disponibilidad.

### Gestión del menú

Permite administrar tipos de platos y los platos disponibles, incluyendo información como descripción, precio y tiempo de preparación.

### Registro de pedidos

Permite registrar pedidos asociados a clientes y meseros y agregar los diferentes platos solicitados.

### Preparación de pedidos

Permite identificar los platos que puede preparar cada cocinero y gestionar el estado de las órdenes.

### Entrega de pedidos

Permite actualizar las órdenes cuando se encuentran listas y cuando son entregadas.

### Reportes

La base de datos incluye consultas para obtener información sobre:

- ventas;
- reservaciones;
- platos más solicitados;
- disponibilidad de mesas;
- pedidos y órdenes;
- historial de clientes.

Las consultas se encuentran en:

```text
sql/consultas_reportes.sql
```

---

## 11. Pruebas

### Pruebas de integridad

```bash
psql -U <usuario> -d restaurante -f sql/pruebas_triggers.sql
```

Estas pruebas comprueban casos válidos y casos que deben ser rechazados por las reglas de negocio.

### Pruebas de particionamiento

```bash
psql -U <usuario> -d restaurante -f sql/pruebas_particiones.sql
```

Estas pruebas permiten verificar el funcionamiento de las particiones y su distribución.

### Consultas de reportes

```bash
psql -U <usuario> -d restaurante -f sql/consultas_reportes.sql
```

También se pueden ejecutar individualmente desde `psql` para demostrar cada caso de uso durante la sustentación.

---

## 12. Orden recomendado para revisar el proyecto

Para comprender o sustentar el proyecto, se recomienda seguir este orden:

1. Revisar el modelo de datos en `sql/restaurante.sql`.
2. Revisar los datos iniciales en `sql/datos_restaurante.sql`.
3. Revisar las reglas de integridad en `sql/triggers.sql`.
4. Ejecutar `sql/pruebas_triggers.sql`.
5. Revisar la configuración de particiones y tablespaces en `sql/particionamiento_fechas.sql`.
6. Ejecutar `sql/pruebas_particiones.sql`.
7. Revisar las consultas y reportes en `sql/consultas_reportes.sql`.
8. Revisar la interfaz web en `public_html/`.
9. Probar las funcionalidades desde los diferentes roles del sistema.

Este orden permite pasar primero por la estructura de la base de datos, después por sus reglas de integridad y distribución física y finalmente por la interfaz y las funcionalidades del restaurante.

