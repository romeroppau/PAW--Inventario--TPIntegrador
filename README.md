# HuellaVet — Inventario

Repositorio del módulo **Inventario** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Gestiona los productos de la tienda, su stock y los movimientos de inventario. Expone dos grupos de endpoints:

- **Tienda** (`/api/tienda/`): el catálogo público, con búsqueda, filtros y paginado.
- **Panel del veterinario** (`/api/inventario/`): alta, edición, baja, carga de stock e historial de movimientos.

## Autores

| Integrante | Legajo |
| --- | --- |
| Ana Paula Romero | 195388 |
| Maria Trinidad Lopez | 197958 |
| Valentino Aimale | 197961 |
| Cristian Tomás Anito | 158887 |

## Documentación

- [Contrato de la API de Inventario](./docs/api/contrato.md): qué recibe y qué devuelve cada endpoint, las validaciones y los códigos de error.
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.

## Versión 0.2.0 — qué se agregó

### Funcionalidades

**Catálogo público (sin sesión)**
- Listado de productos activos y no vencidos, de a 12 por página, ordenados por nombre.
- Búsqueda por texto en el nombre y la descripción, sin distinguir mayúsculas.
- Filtros por categoría (`MEDICAMENTO`, `ALIMENTO`, `ACCESORIO`) y por especie (`PERRO`, `GATO`). Filtrar por una especie también trae los productos para todas las especies.
- Detalle de un producto, con descripción e imagen. Los productos sin stock se muestran como no disponibles.

**Panel del veterinario (solo rol `VETERINARIO`)**
- Listado de todos los productos, incluidos los dados de baja.
- Alta de productos con imagen opcional. Si el producto se crea con stock, ese stock inicial queda registrado como el primer movimiento.
- Edición de los datos del producto. El stock no se edita: solo cambia con la carga de stock, para que toda variación quede en el historial.
- Baja lógica: el producto deja de verse en la tienda, pero se conserva junto con su historial.
- Carga de stock, que registra un movimiento de entrada a nombre del veterinario.
- Historial de movimientos de cada producto, con fecha, tipo, cantidad y quién lo registró.

**Salud del módulo**
- `GET /api/inventario/salud` indica si el módulo funciona y llega a la base.

### Seguridad

- Las rutas del panel exigen la sesión de un veterinario (cookie `huellavet_sesion`, verificada con la clase `Auth` de `comun/`). Sin sesión responden `401`; con otro rol, `403`.
- `POST`, `PUT` y `DELETE` exigen el token CSRF en el header `X-CSRF-Token`.
- Todas las consultas son parametrizadas (sin inyección SQL). En la búsqueda se escapan los comodines de `LIKE`.
- El módulo se conecta con su propio usuario de MySQL, `inventario`, que solo puede leer, crear y modificar productos, y leer y crear movimientos. No puede borrar filas ni modificar el historial.
- Las imágenes se validan por su contenido real (no solo por el tipo que declaran): PNG, JPG o WEBP, de hasta 1 MB.
- La carga de stock y el alta con stock inicial se guardan en una transacción: el stock y su movimiento se guardan juntos o no se guarda ninguno.

### Base de datos

Las tablas se crean con la migración `base/002_inventario_producto.sql`, que está en el repo **ApiGateway**, y los permisos del usuario `inventario` están en `base/permisos.sql` del mismo repo.

| Tabla | Para qué |
| --- | --- |
| `producto` | Los productos de la tienda. La imagen se guarda en la base (`imagen` + `imagen_tipo`) |
| `movimiento_inventario` | Cada variación del stock: tipo (`ENTRADA`, `SALIDA`, `AJUSTE`), motivo, cantidad, fecha y usuario |

La API usa nombres de campo más simples que los de la tabla (`especie` en lugar de `especie_destino`, `vencimiento` en lugar de `fecha_vencimiento`). La traducción la hace el repositorio.

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/ProductoControlador.php` | Un método por endpoint. Controla la sesión, el rol y el CSRF |
| `src/ProductoRepositorio.php` | Todas las consultas a `producto` y `movimiento_inventario`, y las transacciones |
| `src/ValidadorProducto.php` | Valida los datos del alta, la edición y la carga de stock |
| `src/Imagen.php` | Convierte la imagen entre data URL (como viaja en el JSON) y bytes (como se guarda en la base) |
| `docs/api/contrato.md` | El contrato de la API |

## Cómo correrlo

Igual que Backend, este módulo no se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway**, junto con Nginx, el código común (`comun/`) y los demás módulos. Nginx deriva `/api/tienda/` y `/api/inventario/` a este módulo.

Los pasos para levantar todo están en el README de [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador#cómo-correrlo). Una vez levantado:

```bash
curl http://localhost:10000/api/inventario/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Inventario"}`.

## Pendiente

- **Pruebas automáticas (PHPUnit)** del validador, de la conversión de imágenes y del repositorio contra el MySQL local, con la misma estructura que las de Backend. En esta versión el módulo se probó a mano, endpoint por endpoint, con `curl` y desde el frontend.
- **Insumos internos** (tabla `insumo` y su ABM): quedan para cuando existan las consultas del módulo Pacientes, que son las que los consumen. Por eso la columna `insumo_id` de `movimiento_inventario` todavía no tiene clave foránea.
- **Salidas de stock**: se generan con las ventas de la tienda y con las consultas, que llegan en entregas posteriores.
- **Campos del DER que todavía no usa la API**: `huellitas`, `etapa_vida` y `peso_kg` existen en la tabla, pero se van a usar con el programa Huellitas y con el motor de recomendaciones.
