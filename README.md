# HuellaVet — Inventario

Repositorio del módulo **Inventario** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Gestiona los productos de la tienda, los insumos internos de la veterinaria, su stock y los movimientos de inventario. Expone tres grupos de endpoints:

- **Tienda** (`/api/tienda/`): el catálogo público, con búsqueda, filtros y paginado.
- **Panel del veterinario** (`/api/inventario/`): productos e insumos (alta, edición, baja, carga de stock e historial de movimientos).
- **Interno** (`/internal/`): el descuento de stock que pide el módulo Pacientes al finalizar una consulta. No es público.

## Autores

| Integrante | Legajo |
| --- | --- |
| Ana Paula Romero | 195388 |
| Maria Trinidad Lopez | 197958 |
| Valentino Aimale | 197961 |
| Cristian Tomás Anito | 158887 |

## Documentación

- [Contrato de productos y tienda](./docs/api/contrato.md): qué recibe y qué devuelve cada endpoint de productos, las validaciones y los códigos de error.
- [Contrato de insumos](./docs/api/contrato-insumos.md): los endpoints de insumos, de uso exclusivo del veterinario.
- [Contrato interno](./docs/api/contrato-interno.md): el descuento de stock entre módulos (`/internal/stock/descontar`).
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.

## Versión 0.3.0 — qué se agregó (Entrega 4)

### Funcionalidades

**Insumos (solo rol `VETERINARIO`)**

Los insumos son los materiales internos de la veterinaria que se usan en las consultas y no se venden (jeringas, gasas, vacunas). No tienen rutas en `/api/tienda/`: el cliente nunca los ve.

- Listado de todos los insumos, incluidos los dados de baja.
- Alta, edición (nombre y costo) y baja lógica. El costo puede ser 0, por ejemplo para un insumo donado.
- **Estado automático según el stock:** `EN_FALTA` si el stock es 0 y `DISPONIBLE` si es mayor que 0. Nadie lo elige a mano: se recalcula cada vez que cambia el stock.
- **Stock bajo:** un insumo con 10 unidades o menos se marca con `stock_bajo: true`, y el panel lo destaca.
- **Carga de stock**, que registra un movimiento de entrada.
- **Retiro manual**, para las salidas que no vienen de una consulta (por ejemplo, el veterinario se lleva una unidad). Registra un movimiento de salida, así toda salida queda en el historial. No se puede retirar más de lo que hay.
- **Movimientos**: el historial de todos los insumos, con filtro opcional por insumo.

**Descuento interno de stock**
- `POST /internal/stock/descontar`: cuando Pacientes finaliza una consulta, descuenta los insumos y productos usados, todo junto en una transacción (si a uno le falta stock, no se descuenta ninguno). Un mismo pedido repetido no descuenta dos veces. Al descontar un insumo también se actualiza su estado.

### Seguridad (lo nuevo)

- Las rutas de insumos exigen la sesión de un veterinario, y `POST`, `PUT` y `DELETE` el token CSRF, igual que las de productos.
- La carga y el retiro de stock bloquean la fila del insumo mientras se guardan (`SELECT ... FOR UPDATE`, dentro de una transacción): si dos pedidos llegan al mismo tiempo, el segundo espera, así ninguno trabaja con un stock viejo y el stock nunca queda negativo.
- `/internal/` no se publica (Nginx lo bloquea desde afuera) y además exige el token entre servicios en el header `X-Servicio-Token`.

### Pruebas automáticas

Pruebas PHPUnit en `tests/` (por ahora, las del validador de insumos). GitHub Actions las corre en cada push y pull request. Para correrlas en la computadora, desde la raíz del repo (hace falta PHP y Composer):

```bash
composer install
vendor/bin/phpunit --testdox
```

Las pruebas de punta a punta de los endpoints (pedidos con `curl` contra el sistema levantado con Docker) están en el repo **ApiGateway**: `pruebas/casos/03_insumos.sh`.

## Versión 0.2.0 — qué se agregó (Entrega 3)

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
- El módulo se conecta con su propio usuario de MySQL, `inventario`, que solo puede leer, crear y modificar productos e insumos, y leer y crear movimientos. No puede borrar filas ni modificar el historial.
- Las imágenes se validan por su contenido real (no solo por el tipo que declaran): PNG, JPG o WEBP, de hasta 1 MB.
- La carga de stock y el alta con stock inicial se guardan en una transacción: el stock y su movimiento se guardan juntos o no se guarda ninguno.

## Base de datos

Las tablas se crean con las migraciones del repo **ApiGateway** (`base/002_inventario_producto.sql` y `base/003_inventario_insumo.sql`). Los permisos del usuario `inventario` están en `base/permisos/`, un archivo por migración.

| Tabla | Para qué |
| --- | --- |
| `producto` | Los productos de la tienda. La imagen se guarda en la base (`imagen` + `imagen_tipo`) |
| `insumo` | Los insumos internos: nombre, stock, costo, estado y si está activo |
| `movimiento_inventario` | Cada variación del stock de un producto o de un insumo (exactamente uno de los dos): tipo (`ENTRADA`, `SALIDA`, `AJUSTE`), motivo, cantidad, fecha y usuario |

La API usa nombres de campo más simples que los de la tabla (`especie` en lugar de `especie_destino`, `vencimiento` en lugar de `fecha_vencimiento`). La traducción la hace el repositorio.

En los movimientos de insumos, el retiro manual se guarda como `SALIDA` con motivo `AJUSTE`, y el panel lo muestra como "Retiro manual (sin consulta)".

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas de `src/rutas/` y responde 500 si algo falla de forma inesperada. Las clases se cargan solas (autoload) |
| `src/rutas/productos.php` | Rutas de la tienda y del panel de productos |
| `src/rutas/insumos.php` | Rutas de insumos |
| `src/rutas/interno.php` | Ruta interna de descuento de stock |
| `src/ProductoControlador.php` | Un método por endpoint de productos. Controla la sesión, el rol y el CSRF |
| `src/ProductoRepositorio.php` | Todas las consultas a `producto` y sus movimientos, y las transacciones |
| `src/ValidadorProducto.php` | Valida los datos del alta, la edición y la carga de stock de un producto |
| `src/Imagen.php` | Convierte la imagen entre data URL (como viaja en el JSON) y bytes (como se guarda en la base) |
| `src/Insumo.php` | La clase `Insumo` del UML: las reglas del insumo (estado según el stock, stock bajo, reponer y descontar). No tiene SQL |
| `src/InsumoControlador.php` | Un método por endpoint de insumos |
| `src/InsumoRepositorio.php` | Todas las consultas a `insumo` y sus movimientos, y las transacciones |
| `src/InsumoValidador.php` | Valida los datos del alta, la edición y las cantidades de carga y retiro de un insumo |
| `src/StockInternoControlador.php` | El endpoint interno: valida el token entre servicios y el pedido |
| `src/StockInternoRepositorio.php` | Descuenta el stock de productos e insumos en una transacción y registra los movimientos |
| `tests/` | Pruebas PHPUnit (`bootstrap.php` carga las clases de `src/`) |
| `docs/api/` | Los contratos de la API |

## Cómo correrlo

Igual que Backend, este módulo no se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway**, junto con Nginx, el código común (`comun/`) y los demás módulos. Nginx deriva `/api/tienda/` y `/api/inventario/` a este módulo.

Los pasos para levantar todo están en el README de [Backend](https://github.com/romeroppau/PAW--Backend--TPIntegrador#cómo-correrlo). Una vez levantado:

```bash
curl http://localhost:10000/api/inventario/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Inventario"}`.

Para probar con datos, el repo **ApiGateway** tiene datos de prueba (`base/semillas/`): productos, insumos y la cuenta de la veterinaria. Las cuentas y contraseñas están en `base/semillas/README.md` de ese repo.

## Pendiente

- **Campos del DER que todavía no usa la API**: `huellitas`, `etapa_vida` y `peso_kg` de `producto` existen en la tabla, pero se van a usar con el programa Huellitas y con el motor de recomendaciones (Entrega 5).
- **Ventas de la tienda**: las salidas de stock por venta llegan con el carrito y las compras (Entrega 5).
