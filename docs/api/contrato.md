# Contrato de la API — Productos y tienda (módulo Inventario)

Qué recibe y qué devuelve cada endpoint de productos y movimientos de inventario. El frontend programa contra este documento. Si un endpoint cambia, este archivo se actualiza en el mismo commit.

Las reglas generales que valen para todos los módulos (formato JSON, formato de errores, cookie de sesión y token CSRF) están en el [contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md) y en el [contrato de autenticación](https://github.com/romeroppau/PAW--Backend--TPIntegrador/blob/main/docs/api/contrato.md).

## Resumen

| Método y ruta | Quién | Para qué |
|---|---|---|
| `GET /api/tienda/productos` | Público | Catálogo: productos disponibles, con búsqueda, filtros y paginado |
| `GET /api/tienda/productos/{id}` | Público | Detalle de un producto del catálogo, con imagen |
| `GET /api/inventario/productos` | Veterinario | Listado completo para el panel, incluidos los dados de baja |
| `GET /api/inventario/productos/{id}` | Veterinario | Un producto con todos sus datos, para el formulario de edición |
| `POST /api/inventario/productos` | Veterinario | Crear un producto |
| `PUT /api/inventario/productos/{id}` | Veterinario | Editar un producto (no cambia el stock) |
| `DELETE /api/inventario/productos/{id}` | Veterinario | Dar de baja un producto (baja lógica) |
| `POST /api/inventario/productos/{id}/reactivar` | Veterinario | Deshacer la baja |
| `POST /api/inventario/productos/{id}/stock` | Veterinario | Cargar stock en un lote (uno que ya existe o uno nuevo). Registra un movimiento de entrada |
| `POST /api/inventario/productos/{id}/retiro` | Veterinario | Retirar stock a mano (de un lote o de los que vencen primero). Registra una salida por ajuste |
| `PUT /api/inventario/productos/{id}/lotes/{lote}` | Veterinario | Corregir el número del fabricante o el vencimiento de un lote (26/11) |
| `GET /api/inventario/productos/{id}/movimientos` | Veterinario | Historial de movimientos de un producto |
| `GET /api/inventario/productos/movimientos` | Veterinario | Movimientos de todos los productos, con filtros (Entrega 5) |
| `GET /api/inventario/salud` | Público | Indica si el módulo funciona y llega a la base |

Los insumos internos no forman parte de esta entrega: se usan al registrar consultas, que todavía no existen (módulo Pacientes).

## Reglas generales

### Acceso

- Las rutas `/api/tienda/` son públicas: no hace falta sesión.
- Las rutas `/api/inventario/productos` exigen la cookie `huellavet_sesion` de un usuario con rol `VETERINARIO`:
  - `401 { "error": "No autenticado" }` si no hay sesión o venció.
  - `403 { "error": "No tiene permisos para realizar esta operación" }` si el rol es otro.
- `POST`, `PUT` y `DELETE` exigen además el header `X-CSRF-Token`. Si falta o no coincide: `403 { "error": "Token CSRF inválido" }`.
- El rol sale siempre del JWT de la cookie, nunca de un dato que mande el frontend.

### El producto

La API usa estos nombres de campo. Algunos no coinciden con las columnas de la tabla `producto` (por ejemplo, `especie` se guarda en `especie_destino` y `vencimiento` en `fecha_vencimiento`): la traducción la hace el módulo y el frontend no necesita conocer la tabla.

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | número | Identificador |
| `nombre` | texto | Nombre del producto |
| `descripcion` | texto o `null` | Descripción |
| `categoria` | texto | `MEDICAMENTO`, `ALIMENTO` o `ACCESORIO` |
| `especie` | texto | `PERRO`, `GATO` o `TODAS` |
| `precio` | número | Precio en pesos, con hasta 2 decimales |
| `stock` | número | Unidades, la suma de sus lotes, vencidos incluidos (solo en las rutas del veterinario) |
| `stock_vendible` | número | Las que se pueden vender o usar: sin los lotes vencidos (solo en las rutas del veterinario, 26/11) |
| `vencimiento` | texto o `null` | Fecha `AAAA-MM-DD` del próximo lote con stock que vence (sin contar los vencidos). `null` si ninguno vence |
| `vence` | booleano | Si tiene vencimiento: cada lote lleva su fecha (solo en las rutas del veterinario, migración 029) |
| `lotes` | lista | Sus lotes (solo en las rutas del veterinario, 26/11): ver [Lotes](#lotes) |
| `imagen` | texto o `null` | La imagen como data URL (ver abajo). Solo en los detalles, nunca en los listados |
| `activo` | booleano | `false` si fue dado de baja (solo en las rutas del veterinario) |

Los valores de `categoria` y `especie` son códigos. El texto que ve el usuario lo arma el frontend:

| Código | Texto sugerido |
|---|---|
| `MEDICAMENTO` | Medicamentos |
| `ALIMENTO` | Alimento balanceado |
| `ACCESORIO` | Accesorios |
| `PERRO` | Perro |
| `GATO` | Gato |
| `TODAS` | Todas las especies |

### Lotes

Desde la migración 028 (26/11) el stock de un producto está en **lotes**, cada uno con su vencimiento:

```json
{ "id": 12, "numero": 1, "nombre": "L1 (FL-2612)", "codigo": "FL-2612", "vencimiento": "2026-12-16", "stock": 8, "vencido": false }
```

- `numero` (migración 029): los lotes de cada producto se numeran 1, 2, 3… en el orden en que se crean; en la pantalla, **L1, L2, L3…** (`nombre`, que suma el número del fabricante si tiene). No se cambia. (`null` solo en un lote que creó el Inventario anterior a la 029.)
- `codigo`: el número de lote del fabricante (el del envase), o `null`. `vencimiento`: `AAAA-MM-DD`, o `null` si no vence.
- **`vence`** (029): si el producto vence, cada lote nuevo lleva su vencimiento; si no vence (por ejemplo, un accesorio), ningún lote lo lleva, su stock va a un único lote sin vencimiento y la pantalla no muestra lotes. Se cambia solo con el stock en 0.
- `lotes` trae los que tienen stock y los vacíos que no vencieron y siguen la regla de `vence` (se les puede cargar stock), el que vence primero arriba (los que no vencen, al final).
- **Se vende y se usa en las consultas lo que no está vencido, empezando por el lote que vence primero.** Si una venta o una consulta necesita más de lo que tiene ese lote, sigue con el siguiente. Una venta cancelada devuelve cada unidad al lote del que salió.
- Un lote vencido con stock (`vencido: true`) no se vende. Al día siguiente de vencer, la tarea diaria lo da de baja (ver `POST /api/inventario/tareas/vencer-lotes`): hasta entonces queda para retirarlo, y Reportes lo avisa. Un producto con **todo** su stock vencido no se ve en la tienda (como antes un producto vencido); sin stock, se ve como "sin stock".
- Los movimientos dicen de qué lote fueron (`lote`).
- Una promoción es de un lote, y el descuento vale para las unidades que le quedan a ese lote (`contrato-tienda.md`, "Promociones").

### Imagen

La imagen viaja dentro del JSON como **data URL**: un texto que empieza con el tipo de la imagen y sigue con su contenido en base64.

```
data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAA...
```

- Tipos aceptados: `image/png`, `image/jpeg` y `image/webp`.
- Tamaño máximo: **1 MB** (de la imagen, antes de pasarla a base64).
- El módulo comprueba que el contenido sea realmente una imagen del tipo declarado; no alcanza con que el texto diga `image/png`.
- Se guarda en la base (columnas `imagen` e `imagen_tipo`) y se devuelve con el mismo formato, listo para usar en `<img src="...">`.
- Los listados no incluyen la imagen, para que sean livianos. Se pide con el detalle del producto.

### Validación de campos

Se aplica en el alta (`POST`) y en la edición (`PUT`). Antes de validar se quitan los espacios al principio y al final de los textos. Si un campo llega con un tipo incorrecto (por ejemplo, un texto donde va un número), se considera inválido.

| Campo | Regla | Mensaje si falta | Mensaje si es inválido |
|---|---|---|---|
| `nombre` | Texto de 2 a 120 caracteres | "El nombre es obligatorio" | "El nombre debe tener entre 2 y 120 caracteres" |
| `descripcion` | Opcional. Texto de hasta 2000 caracteres. Vacío se guarda como `null` | — | "La descripción puede tener hasta 2000 caracteres" |
| `categoria` | Uno de los códigos de categoría | "La categoría es obligatoria" | "La categoría no es válida" |
| `especie` | Uno de los códigos de especie | "La especie es obligatoria" | "La especie no es válida" |
| `precio` | Número mayor que 0 y menor que 100.000.000, con hasta 2 decimales | "El precio es obligatorio" | "El precio debe ser un número mayor que 0 con hasta 2 decimales" |
| `stock` | Solo en el alta. Entero de 0 a 1.000.000 | "El stock es obligatorio" | "El stock debe ser un número entero entre 0 y 1.000.000" |
| `vence` | `true` o `false` (029). Obligatorio en el alta; en la edición es opcional (si no viene, no cambia) y solo se puede cambiar con el stock en 0 | "Indicá si vence o no" | "Indicá si vence o no" / "Se puede cambiar solo sin stock: retirá o vendé las N unidades primero" |
| `vencimiento` | Solo en el alta: el del primer lote (L1). Fecha válida `AAAA-MM-DD`, no anterior a hoy. Si `vence` y hay stock inicial, obligatorio; si no vence, tiene que ir vacío | "Este producto vence: poné la fecha de vencimiento del lote" | "La fecha de vencimiento no es válida" / "La fecha de vencimiento no puede ser anterior a hoy" / "Este producto no vence: dejá el vencimiento vacío" |
| `lote` | Solo en el alta: el número de lote del fabricante del primer lote. Opcional, hasta 40 caracteres | — | "El número de lote del fabricante puede tener hasta 40 caracteres" |
| `imagen` | Opcional. Data URL según las reglas de [Imagen](#imagen) | — | "La imagen debe ser PNG, JPG o WEBP" / "La imagen no puede superar 1 MB" |

Si hay errores, se responde `400` con un mensaje por campo:

```json
{
  "error": "Datos inválidos",
  "campos": {
    "precio": "El precio debe ser un número mayor que 0 con hasta 2 decimales",
    "categoria": "La categoría es obligatoria"
  }
}
```

Los campos que no figuran en la tabla (por ejemplo `activo` o `id`) se ignoran.

### Identificadores en la ruta

Si el `{id}` de la ruta no es un número entero positivo, o no existe un producto con ese id, se responde `404 { "error": "Producto no encontrado" }`.

---

## GET /api/tienda/productos

Catálogo público. Devuelve solo productos **activos** que no tienen todo su stock vencido (ver [Lotes](#lotes)), ordenados por nombre. Los productos sin stock también aparecen, con `disponible: false` (`disponible`: tiene stock sin vencer).

**Parámetros (todos opcionales):**

| Parámetro | Descripción |
|---|---|
| `buscar` | Texto a buscar en el nombre y la descripción (sin distinguir mayúsculas). Se usan hasta 100 caracteres |
| `categoria` | Código de categoría. Un valor desconocido se ignora |
| `especie` | Código de especie. `PERRO` o `GATO` devuelven también los productos para `TODAS` |
| `pagina` | Número de página, desde 1. Si no es un entero válido se usa 1 |

Cada página trae hasta **12** productos. Si una página no tiene resultados, `datos` llega vacío: el frontend deja de pedir más.

**Ejemplo:** `GET /api/tienda/productos?buscar=collar&especie=PERRO&pagina=1`

**200 OK:**

```json
{
  "datos": [
    {
      "id": 7,
      "nombre": "Collar reflectivo",
      "categoria": "ACCESORIO",
      "especie": "PERRO",
      "precio": 8500.00,
      "vencimiento": null,
      "disponible": true,
      "promocion": { "porcentaje": 30, "precio": 5950.00, "hasta": "2026-12-10", "quedan": 8 }
    }
  ],
  "pagina": 1,
  "por_pagina": 12,
  "total": 1
}
```

`total` es la cantidad de productos que cumplen los filtros, sumando todas las páginas.

`promocion` (Entrega 5): la promoción vigente del producto, o `null` si no tiene. `precio` sigue siendo el precio sin descuento; `promocion.precio` es el que se cobra (redondeado a centavos) y `promocion.hasta`, el último día. `promocion.quedan` (26/11): las unidades con descuento, las que le quedan al lote de la promoción; las demás se cobran a `precio`. Ver `contrato-tienda.md`, sección "Promociones".

---

## GET /api/tienda/productos/{id}

Detalle público de un producto, con descripción e imagen.

**200 OK:**

```json
{
  "id": 7,
  "nombre": "Collar reflectivo",
  "descripcion": "Collar ajustable con banda reflectiva.",
  "categoria": "ACCESORIO",
  "especie": "PERRO",
  "precio": 8500.00,
  "vencimiento": null,
  "disponible": true,
  "promocion": null,
  "imagen": "data:image/jpeg;base64,/9j/4AAQSkZJRg..."
}
```

**Errores:** `404 { "error": "Producto no encontrado" }` si no existe, está dado de baja o tiene todo su stock vencido.

---

## GET /api/inventario/productos

Listado para el panel del veterinario: **todos** los productos, incluidos los dados de baja y los vencidos, ordenados por nombre. Sin imagen ni descripción, y sin paginado.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 7,
      "nombre": "Collar reflectivo",
      "categoria": "ACCESORIO",
      "especie": "PERRO",
      "precio": 8500.00,
      "stock": 14,
      "stock_vendible": 14,
      "vencimiento": null,
      "activo": true,
      "lotes": [{ "id": 3, "numero": 1, "nombre": "L1", "codigo": null, "vencimiento": null, "stock": 14, "vencido": false }]
    }
  ],
  "limite_stock_bajo": 10
}
```

`limite_stock_bajo` (Entrega 5): el límite de stock bajo configurado (Configuración, 10 por defecto), para el filtro "solo stock bajo" del listado.

---

## GET /api/inventario/productos/{id}

Un producto con todos sus datos. Lo usa el formulario de edición para precargar los campos.

**200 OK:**

```json
{
  "id": 7,
  "nombre": "Collar reflectivo",
  "descripcion": "Collar ajustable con banda reflectiva.",
  "categoria": "ACCESORIO",
  "especie": "PERRO",
  "precio": 8500.00,
  "stock": 14,
  "stock_vendible": 14,
  "vencimiento": null,
  "activo": true,
  "imagen": "data:image/jpeg;base64,/9j/4AAQSkZJRg...",
  "lotes": [{ "id": 3, "numero": 1, "nombre": "L1", "codigo": null, "vencimiento": null, "stock": 14, "vencido": false }]
}
```

**Errores:** `404` si no existe.

---

## POST /api/inventario/productos

Crea un producto activo.

**Pedido:**

```json
{
  "nombre": "Alimento cachorro 3 kg",
  "descripcion": "Alimento balanceado para cachorros de razas medianas.",
  "categoria": "ALIMENTO",
  "especie": "PERRO",
  "precio": 15999.90,
  "stock": 20,
  "vence": true,
  "vencimiento": "2027-03-31",
  "lote": "FL-0425",
  "imagen": "data:image/png;base64,iVBORw0KGgo..."
}
```

`descripcion`, `vencimiento`, `lote` e `imagen` son opcionales (`vencimiento`, obligatorio si `vence` y hay stock). `vencimiento` y `lote` (el número del fabricante) son los del primer lote, L1 (26/11): si hay stock, vencimiento o número de lote, se crea ese lote con el stock.

Si `stock` es mayor que 0, se registra también un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO` por esa cantidad, así el stock inicial queda en el historial.

**201 Created:** el producto creado, con el mismo formato que `GET /api/inventario/productos/{id}`.

**Errores:** `400` si algún campo falta o es inválido (ver [Validación de campos](#validación-de-campos)).

---

## PUT /api/inventario/productos/{id}

Edita los datos de un producto. Se mandan todos los campos del alta **menos `stock`, `vencimiento` y `lote`**: el stock solo cambia con la carga de stock, para que cada variación quede registrada en los movimientos, y el vencimiento es de cada lote (26/11; un lote mal cargado se corrige con `PUT .../lotes/{lote}`). Si el pedido los trae, se ignoran. `vence` es opcional (029): si no viene, no cambia; para cambiarlo, el producto tiene que estar sin stock (si no, `400` con `campos.vence`).

- `imagen` omitida: se conserva la imagen actual.
- `imagen: null`: se borra la imagen.
- `imagen` con un data URL: se reemplaza.

Un producto dado de baja se puede editar, pero sigue dado de baja.

**200 OK:** el producto actualizado, con el mismo formato que `GET /api/inventario/productos/{id}`.

**Errores:** `400` si algún campo es inválido; `404` si no existe.

---

## DELETE /api/inventario/productos/{id}

Baja lógica: el producto queda con `activo: false`, desaparece del catálogo público y se conserva su historial. No se borra la fila.

**204 No Content**, sin cuerpo. Dar de baja un producto que ya estaba dado de baja también responde `204`.

**Errores:** `404` si no existe.

---

## POST /api/inventario/productos/{id}/reactivar

Deshace la baja: el producto vuelve con `activo: true`, con su stock y su historial, y reaparece en el catálogo (si no tiene todo su stock vencido). Reactivar un producto activo no cambia nada.

**200 OK** con el producto completo (como en `GET /api/inventario/productos/{id}`). **Errores:** `404` si no existe.

---

## POST /api/inventario/productos/{id}/stock

Suma unidades a un lote y registra un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO`, a nombre del veterinario de la sesión. El stock y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido** (26/11), uno de tres:

```json
{ "cantidad": 10, "lote_id": 12 }
{ "cantidad": 10, "lote": { "codigo": "FL-0611", "vencimiento": "2027-06-30" } }
{ "cantidad": 10 }
```

- `lote_id`: a un lote que ya existe (de ese producto, aunque esté vacío), sin vencer y que siga la regla de `vence` (029): si el producto vence, el lote tiene vencimiento; si no, no.
- `lote`: a un lote nuevo, con el número que sigue (L4 si ya tiene L1, L2 y L3). `codigo` (el del fabricante) es opcional; `vencimiento` es obligatorio si el producto vence y tiene que ir vacío si no vence (mismas reglas que en el alta).
- Sin ninguno de los dos: solo si el producto no vence, al lote sin vencimiento (lo crea si no tiene). Es lo que usa la pantalla para lo que no vence.

**200 OK:** el stock total y el lote, como queda:

```json
{ "id": 7, "stock": 24, "lote": { "id": 15, "numero": 4, "nombre": "L4 (FL-0611)", "codigo": "FL-0611", "vencimiento": "2027-06-30", "stock": 10, "vencido": false } }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000:

  ```json
  {
    "error": "Datos inválidos",
    "campos": { "cantidad": "La cantidad debe ser un número entero entre 1 y 100.000" }
  }
  ```

- `400` con `campos.lote_id`: "Ese lote no es de este producto", "Ese lote está vencido: cargá en otro o creá uno nuevo", "Ese lote no sigue la regla del producto (si vence, cada lote lleva su vencimiento): cargá en otro o creá uno nuevo" o, si el producto vence y no vino lote, "Este producto vence: elegí un lote o creá uno nuevo con su vencimiento".
- `400` con `campos["lote.codigo"]` o `campos["lote.vencimiento"]` si el lote nuevo es inválido ("Este producto vence: poné la fecha de vencimiento del lote", "Este producto no vence: dejá el vencimiento vacío"…).
- `404`, si el producto no existe.
- `409`, si el producto está dado de baja: `{ "error": "El producto está dado de baja" }`.

---

## PUT /api/inventario/productos/{id}/lotes/{lote}

(26/11) Corrige el número del fabricante o el vencimiento de un lote mal cargado: `{ "codigo": "FL-0611", "vencimiento": "2027-06-30" }`. `codigo` es opcional (vacío o `null` lo borra); `vencimiento` es obligatorio si el producto vence y tiene que ir vacío si no vence (029). El vencimiento puede ser pasado. No cambia el stock ni el número (L1, L2…).

**200 OK:** el producto completo, como en `GET /api/inventario/productos/{id}`. **Errores:** `400` (`campos.codigo` o `campos.vencimiento`) y `404` si el producto no existe o el lote no es suyo.

---

## POST /api/inventario/productos/{id}/retiro

Resta unidades del stock a mano (un producto vencido, roto o usado en el local), como el retiro de insumos. Registra un movimiento `SALIDA` con motivo `AJUSTE` ("Retiro manual" en la pantalla), a nombre del veterinario de la sesión. El stock y el movimiento se guardan juntos, y el stock nunca queda negativo, aunque lleguen dos retiros al mismo tiempo.

**Pedido:** `{ "cantidad": 3, "lote_id": 12 }`. Sin `lote_id` (26/11), sale de los lotes que vencen primero, los vencidos antes que nada.

**200 OK:**

```json
{ "id": 7, "stock": 21 }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000 (el mismo cuerpo que en la carga).
- `404`, si el producto no existe.
- `409`, si el producto está dado de baja: `{ "error": "El producto está dado de baja" }`.
- `409`, si no hay tantas unidades (en el lote, si se eligió uno): `{ "error": "Stock insuficiente", "stock": 2 }`.
- `400` con `campos.lote_id`, si el lote no es de este producto.

---

## GET /api/inventario/productos/{id}/movimientos

Historial de movimientos de un producto, del más reciente al más antiguo.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 31,
      "fecha": "2026-10-17 19:30:00",
      "tipo": "ENTRADA",
      "motivo": "CARGA_VETERINARIO",
      "cantidad": 10,
      "usuario": "Nombre del veterinario"
    }
  ]
}
```

- `tipo`: `ENTRADA`, `SALIDA` o `AJUSTE`. En esta entrega solo se generan entradas; las salidas llegan con las ventas y las consultas.
- `usuario`: nombre de quien registró el movimiento, o `null` si no lo registró una persona.
- (Entrega 5) Además trae `producto_id`, `producto`, `consulta_id` y `compra`, como el listado de abajo.
- (26/11) `lote`: `{ "id", "numero", "nombre", "codigo", "vencimiento" }` del lote del movimiento (`nombre`: "L2 (FL-0611)", 029). Una venta o una consulta que usó dos lotes tiene un movimiento por lote.

**Errores:** `404` si el producto no existe.

## GET /api/inventario/productos/movimientos

(Entrega 5) Movimientos de **todos** los productos, del más reciente al más antiguo: la pantalla "Productos → Movimientos" de la veterinaria.

**Parámetros (opcionales):** `producto` (id de un producto: solo los suyos; si no es un id válido se ignora), `desde` y `hasta` (`AAAA-MM-DD`, inclusive).

**200 OK:** `{ "datos": [ ... ] }`, cada uno con el formato de arriba más:

- `producto_id` y `producto`: el producto (su nombre).
- `consulta_id` y `compra` (Entrega 5): de dónde salió el movimiento, para enlazarlo. `consulta_id` es la consulta en la que se usó (motivo `CONSULTA`); `compra`, el número de la compra de la tienda (`V-000123`, motivos `VENTA` y `VENTA_CANCELADA`). Si no corresponde, `null`. Salen del `id_operacion` del movimiento (`src/ReferenciaMovimiento.php`).

**Errores:** `400 { "error": "Datos inválidos", "campos": { "desde": "..." } }` si una fecha no tiene el formato `AAAA-MM-DD` o `desde` es posterior a `hasta`.

---

## POST /api/inventario/tareas/vencer-lotes

(27/11) La baja diaria de los lotes vencidos, **de productos y de insumos**. La llama el motor diario (GitHub Actions, `motor-diario.yml` del ApiGateway) con el header `X-Tarea-Token` (el secret `TAREAS_TOKEN`, el mismo de `/api/tienda/tareas/vencer`). Sin token válido: `401`.

Por cada lote vencido (su vencimiento es anterior a hoy, en hora de Argentina) que todavía tiene stock:

- el lote queda en 0 y el stock del producto o insumo baja (y su próximo vencimiento se recalcula);
- queda un movimiento `SALIDA` con motivo **`VENCIMIENTO`** (migración 031) por todo lo que tenía el lote, sin usuario (`usuario: null`; la pantalla dice "Tarea diaria") y con `id_operacion` `vencimiento-producto-{lote}` o `vencimiento-insumo-{lote}`;
- cada veterinaria recibe un aviso por la campana (tipo `STOCK_BAJO`): "Dimos de baja el lote L2 (FL-2612) del producto Vacuna X: 8 unidades vencieron el 25/11/2026. Stock actual: 12. Lo ves en Movimientos."

Lo que vence hoy todavía se usa: se da de baja mañana. Cada lote va en su propia transacción; si la tarea corre dos veces, la segunda no encuentra nada. Los avisos de los últimos dos días se reintentan en cada corrida (Backend no repite un aviso con el mismo `id_operacion`).

**200 OK:** `{ "lotes_productos": 1, "lotes_insumos": 2, "unidades": 15, "avisos": 3 }`

---

## GET /api/inventario/salud

Indica si el módulo funciona. Hace una consulta mínima a la base, igual que la salud de Backend.

**200 OK:** `{ "estado": "funciona", "modulo": "Inventario" }`

**503:** `{ "estado": "sin conexión a la base", "modulo": "Inventario" }`

---

## Cambios que necesita el frontend

Para que el frontend actual funcione con este contrato:

1. **Catálogo** (`catalogo/catalogo.html`): los `value` de los filtros pasan a ser los códigos, por ejemplo `<option value="ALIMENTO">Alimento balanceado</option>` y `<option value="PERRO">Perro</option>`.
2. **Tarjetas y detalle** (`js/catalogo.js`, `js/producto.js`): mostrar el texto de la categoría y de la especie a partir del código (tabla de arriba).
3. **Formulario del panel** (`panel/producto-form.html`): `categoria` y `especie` pasan a ser `<select>` con los códigos, en vez de campos de texto libre.
4. **Edición** (`js/panel.js`): precargar el formulario con `GET /api/inventario/productos/{id}`, en lugar de pedir el listado completo y buscar el producto.
