# HuellaVet — Inventario

Repositorio del módulo **Inventario** del Trabajo Práctico Integrador de **Programación en Ambiente Web (PAW)** — UNLu, 2026 — Grupo **La 25**.

Es el módulo de **lo que se vende y lo que se usa**: los productos de la tienda, los insumos internos de la veterinaria, su stock y sus movimientos, y la **tienda** completa (carrito, compra para retirar, pago con Mercado Pago, cancelación con reintegro y promociones).

## Autores

| Integrante | Legajo |
| --- | --- |
| Ana Paula Romero | 195388 |
| Maria Trinidad Lopez | 197958 |
| Valentino Aimale | 197961 |
| Cristian Tomás Anito | 158887 |

## Documentación

- [Contrato de productos y catálogo](./docs/api/contrato.md)
- [Contrato de insumos](./docs/api/contrato-insumos.md)
- [Contrato de la tienda](./docs/api/contrato-tienda.md): carrito, compras, pagos, plazos, reintegros, promociones y avisos.
- [Contrato interno](./docs/api/contrato-interno.md): el descuento de stock que pide Pacientes.
- [Contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md): reglas comunes a todos los módulos.

---

## Qué tiene

### Catálogo público (sin sesión)

| Endpoint | Qué hace |
| --- | --- |
| `GET /api/tienda/productos` | Productos activos y no vencidos, de a 12 por página. Búsqueda por texto, filtros por categoría (`MEDICAMENTO`, `ALIMENTO`, `ACCESORIO`) y especie (`PERRO`, `GATO`; trae también los de todas las especies) |
| `GET /api/tienda/productos/{id}`, `…/{id}/imagen` | Detalle e imagen. Sin stock, se muestra como no disponible |
| `GET /api/tienda/promociones` | Los productos con descuento vigente |

### Tienda: compra para retirar (cliente)

| Endpoint | Qué hace |
| --- | --- |
| `GET /api/tienda/carrito`, `POST …/items`, `PUT` y `DELETE …/items/{producto_id}` | El carrito |
| `POST /api/tienda/carrito/confirmar` | Arma la factura `V-…` en `PENDIENTE_PAGO` y reserva el stock |
| `POST /api/tienda/compras/{numero}/pago`, `…/pago/confirmar` | Pago con Mercado Pago (Checkout Pro) |
| `POST /api/tienda/pagos/aviso` | Webhook de Mercado Pago (firma verificada con `MP_WEBHOOK_SECRET`) |
| `POST /api/tienda/pagos/simulado` | Pago simulado, solo en `local` y `ci` |
| `GET /api/tienda/compras`, `…/{numero}`, `…/{numero}/factura` | Mis compras y su factura |
| `POST /api/tienda/compras/{numero}/cancelar` | Cancelar: el stock vuelve y, si estaba paga, se hace el reintegro |

El recorrido de una compra:

```
Confirmar → PENDIENTE_PAGO (stock reservado)
  ├─ no paga en 15 minutos o se arrepiente → CANCELADA, el stock vuelve
  └─ paga con Mercado Pago                 → PAGADA: 5 días hábiles para retirar
       ├─ la retira y la veterinaria lo marca → ENTREGADA
       ├─ la cancela él                       → CANCELADA, reintegro del 75 % (con 48 h o más de anticipación) o del 50 %
       └─ no la retira a tiempo               → CANCELADA sin reintegro, el stock vuelve
```

Los plazos y porcentajes son los valores por defecto: la veterinaria los cambia en su **Configuración**, y cada compra guarda los que valían al confirmarse.

### Panel de la veterinaria (solo `VETERINARIO`)

| Tema | Endpoints |
| --- | --- |
| Productos | `GET`, `POST /api/inventario/productos`, `GET`, `PUT`, `DELETE …/{id}`, `POST …/{id}/reactivar`, `…/{id}/stock`, `GET …/{id}/movimientos` |
| Insumos | `GET`, `POST /api/inventario/insumos`, `GET`, `PUT`, `DELETE …/{id}`, `POST …/{id}/reactivar`, `…/{id}/stock`, `…/{id}/retiro`, `POST` y `DELETE …/{id}/pedido` ("ya pedido" al proveedor), `GET …/{id}/movimientos`, `GET …/insumos/movimientos` |
| Ventas | `GET /api/inventario/compras` (pestañas, búsqueda por número o comprador), `GET …/compras/resumen`, `POST /api/tienda/compras/{numero}/entregada` |
| Promociones | `GET`, `POST /api/inventario/promociones`, `DELETE …/{id}` (las publica desde "Qué liquidar" de Reportes: entre 5 y 90 %, hasta 90 días y nunca después del vencimiento) |
| Salud | `GET /api/inventario/salud` |

Reglas de los insumos: el estado (`DISPONIBLE` o `EN_FALTA`) se calcula solo según el stock; con 10 unidades o menos se marca como **stock bajo**; el retiro manual queda como salida en el historial y no se puede retirar más de lo que hay.

### Interno (no es público)

- `POST /internal/stock/descontar`: Pacientes descuenta los insumos y productos usados al finalizar una consulta, todo en una transacción. Un pedido repetido no descuenta dos veces.

### Tarea programada

- `POST /api/tienda/tareas/vencer` (con `X-Tarea-Token`): cancela las compras vencidas, manda los recordatorios de retiro y los avisos de cancelación, y reintenta los reintegros que Mercado Pago no pudo hacer.
- Además, cada pedido a la tienda cancela "al pasar" las compras vencidas, así nunca se muestra stock reservado de más aunque la tarea se atrase.

### Avisos

Inventario le pide a Backend los avisos (`POST /internal/notificaciones`, tipo `COMPRA`) al comprador y a la veterinaria: compra pagada, recordatorios de retiro, cancelación y reintegro. Salen en la campana del sitio y por WhatsApp.

---

## Seguridad

- Las rutas del panel exigen la sesión de un veterinario; las del carrito y las compras, la de un cliente, que solo ve **sus** compras. Sin sesión, `401`; con otro rol, `403`.
- `POST`, `PUT` y `DELETE` exigen el token CSRF en `X-CSRF-Token`.
- Todas las consultas son parametrizadas; en la búsqueda se escapan los comodines de `LIKE`.
- El stock se bloquea mientras se modifica (`SELECT … FOR UPDATE` dentro de una transacción): dos pedidos al mismo tiempo nunca dejan el stock negativo ni venden de más.
- El precio se toma siempre de la base, nunca del pedido del navegador.
- El webhook de Mercado Pago verifica la firma, y el pago se confirma consultando a Mercado Pago, no por lo que dice la URL de vuelta. Se usan **siempre credenciales de prueba**.
- `MP_ACCESS_TOKEN` y `MP_WEBHOOK_SECRET` solo los recibe este módulo y nunca se escriben en los logs.
- Las imágenes se validan por su contenido real: PNG, JPG o WEBP, de hasta 1 MB.
- El usuario de MySQL `inventario` solo puede trabajar sobre sus tablas y no puede borrar el historial de movimientos.
- `/internal/` no se publica (Nginx lo bloquea) y exige `X-Servicio-Token`.

---

## Base de datos

Las tablas se crean con las migraciones del repo **ApiGateway** (`base/`): `002` (productos y movimientos), `003` (insumos), `010` (tienda), `012`, `013`, `015` y `017` (cancelación, pagos devueltos, plazos y reintegros) y `016` (promociones). Los permisos del usuario `inventario` están en `base/permisos/`.

| Tabla | Para qué |
| --- | --- |
| `producto` | Los productos de la tienda, con su imagen |
| `insumo` | Los insumos internos: stock, costo, estado y si ya se pidieron |
| `movimiento_inventario` | Cada variación del stock de un producto o un insumo: tipo, motivo, cantidad, fecha y usuario |
| `carrito`, `item_carrito` | El carrito de cada cliente; al confirmarse queda como el detalle de la compra, con el precio de cada ítem en ese momento |
| `factura_compra` | La compra (`V-…`): estado, plazos, política de reintegro y motivo de cancelación |
| `pago` | Los pagos de Mercado Pago y sus reintegros |
| `promocion` | Los descuentos publicados |

---

## Cómo está organizado el código

| Archivo | Qué hace |
| --- | --- |
| `src/index.php` | Punto de entrada: carga el código común, registra las rutas y responde 500 si algo falla de forma inesperada |
| `src/rutas/` | Rutas por tema: `productos`, `insumos`, `tienda`, `promociones`, `interno` |
| `src/ProductoControlador.php`, `ProductoRepositorio.php`, `ValidadorProducto.php`, `ImagenProductoControlador.php`, `Imagen.php` | Productos y sus imágenes |
| `src/Insumo.php`, `InsumoControlador.php`, `InsumoRepositorio.php`, `InsumoValidador.php` | Insumos |
| `src/CarritoControlador.php`, `CarritoRepositorio.php` | El carrito |
| `src/Compra.php`, `CompraControlador.php`, `CompraRepositorio.php` | Las reglas de la compra (plazos, estados, reintegro) y sus endpoints |
| `src/MercadoPago.php`, `PagoControlador.php`, `PagoRepositorio.php`, `Reintegros.php` | El pago, el webhook y los reintegros |
| `src/Promocion.php`, `PromocionControlador.php`, `PromocionRepositorio.php` | Promociones |
| `src/Vencimientos.php`, `TareaControlador.php` | La tarea programada de la tienda |
| `src/Avisos.php`, `AvisosDeCompra.php` | Los avisos que se le piden a Backend |
| `src/StockInternoControlador.php`, `StockInternoRepositorio.php` | El descuento interno de stock |
| `tests/` | Pruebas PHPUnit |

---

## Cómo correrlo

No se levanta solo: corre dentro del contenedor que arma el repo **ApiGateway** (proceso `127.0.0.1:9002`). Nginx deriva `/api/tienda/` y `/api/inventario/` a este módulo. Los pasos están en el README de [ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador#cómo-correrlo).

```bash
curl http://localhost:10000/api/inventario/salud
```

Tiene que devolver `{"estado":"funciona","modulo":"Inventario"}`.

### Variables propias

| Variable | Para qué |
| --- | --- |
| `DB_USER`, `DB_PASSWORD` | Se los pasa `iniciar.sh` desde `INVENTARIO_DB_USER` e `INVENTARIO_DB_PASSWORD` |
| `JWT_PUBLIC_KEY` | Leer la sesión (no recibe la clave privada) |
| `INTERNAL_TOKEN` | Validar el descuento de stock y pedirle avisos a Backend |
| `TAREAS_TOKEN` | Validar la tarea programada |
| `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET` | Mercado Pago (credenciales de prueba). Con `ENTORNO` `local` o `ci` se usa el pago simulado |
| `SITIO_URL` | Dirección del frontend, para la vuelta desde Mercado Pago |

---

## Pruebas

- **PHPUnit** (`tests/`): reglas de la compra (plazos, estados y reintegros), insumos y su validador, Mercado Pago (firma del webhook), promociones y el descuento interno de stock.

  ```bash
  composer install
  COMUN_DIR=../PAW--ApiGateway--TPIntegrador/comun vendor/bin/phpunit --testdox
  ```

- **Pruebas de humo** (repo ApiGateway, `pruebas/casos/`): `01_general.sh`, `03_insumos.sh`, `04_stock_interno.sh`, `13_tienda.sh` y `14_promociones.sh`.
- **GitHub Actions** corre la sintaxis y PHPUnit en cada push y pull request.

---

## No incluido

- **Huellitas** (programa de puntos): las columnas `huellitas` de `producto` existen, pero no se usan.
- **Recompra de alimento** y **productos recomendados según la mascota** (`etapa_vida` y `peso_kg` de `producto` quedan sin usar).
