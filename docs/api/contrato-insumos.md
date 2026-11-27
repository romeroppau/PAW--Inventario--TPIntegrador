# Contrato de la API — Insumos (uso interno del veterinario)

Qué recibe y qué devuelve cada endpoint de insumos del módulo Inventario. Los insumos son los materiales internos de la veterinaria que se usan en las consultas y no se venden (jeringas, gasas, vacunas, etc.). El frontend programa contra este documento. Si un endpoint cambia, este archivo se actualiza en el mismo commit.

Los productos de la tienda tienen su propio contrato: [contrato.md](contrato.md). Las reglas generales que valen para todos los módulos (formato JSON, formato de errores, cookie de sesión y token CSRF) están en el [contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md) y en el [contrato de autenticación](https://github.com/romeroppau/PAW--Backend--TPIntegrador/blob/main/docs/api/contrato.md).

## Resumen

| Método y ruta | Quién | Para qué |
|---|---|---|
| `GET /api/inventario/insumos` | Veterinario | Listado completo, incluidos los dados de baja |
| `GET /api/inventario/insumos/{id}` | Veterinario | Un insumo, para el formulario de edición |
| `POST /api/inventario/insumos` | Veterinario | Crear un insumo |
| `PUT /api/inventario/insumos/{id}` | Veterinario | Editar un insumo (no cambia el stock) |
| `DELETE /api/inventario/insumos/{id}` | Veterinario | Dar de baja un insumo (baja lógica) |
| `POST /api/inventario/insumos/{id}/reactivar` | Veterinario | Deshacer la baja |
| `POST /api/inventario/insumos/{id}/pedido` | Veterinario | Marcar "Ya pedido" al proveedor (solo con stock bajo) |
| `DELETE /api/inventario/insumos/{id}/pedido` | Veterinario | Desmarcar "Ya pedido" |
| `POST /api/inventario/insumos/{id}/stock` | Veterinario | Cargar stock en un lote (uno que ya existe o uno nuevo). Registra un movimiento de entrada |
| `POST /api/inventario/insumos/{id}/retiro` | Veterinario | Retirar unidades sin una consulta (de un lote o de los que vencen primero). Registra un movimiento de salida |
| `PUT /api/inventario/insumos/{id}/lotes/{lote}` | Veterinario | Corregir el número del fabricante o el vencimiento de un lote (26/11) |
| `GET /api/inventario/insumos/movimientos` | Veterinario | Movimientos de todos los insumos, con filtro opcional por insumo |
| `GET /api/inventario/insumos/{id}/movimientos` | Veterinario | Historial de movimientos de un insumo |

Los insumos **no** tienen rutas en `/api/tienda/`: el cliente nunca los ve.

## Reglas generales

### Acceso

- Todas las rutas exigen la cookie `huellavet_sesion` de un usuario con rol `VETERINARIO`:
  - `401 { "error": "No autenticado" }` si no hay sesión o venció.
  - `403 { "error": "No tiene permisos para realizar esta operación" }` si el rol es otro.
- `POST`, `PUT` y `DELETE` exigen además el header `X-CSRF-Token`. Si falta o no coincide: `403 { "error": "Token CSRF inválido" }`.
- El rol sale siempre del JWT de la cookie, nunca de un dato que mande el frontend.

### El insumo

| Campo | Tipo | Descripción |
|---|---|---|
| `id` | número | Identificador |
| `nombre` | texto | Nombre del insumo |
| `stock` | número | Unidades, la suma de sus lotes, vencidos incluidos |
| `stock_sin_vencer` | número | Las que se pueden usar: sin los lotes vencidos (26/11, migración 029) |
| `vencimiento` | texto o `null` | Fecha `AAAA-MM-DD` del próximo lote con stock que vence (sin contar los vencidos). `null` si ninguno vence (029) |
| `vence` | booleano | Si tiene vencimiento: cada lote lleva su fecha (029) |
| `lotes` | lista | Sus lotes (029): ver [Lotes](#lotes). En el listado, el detalle, el alta, la edición y la corrección de un lote |
| `costo` | número | Lo que le cuesta a la veterinaria, en pesos, con hasta 2 decimales. Puede ser 0 (por ejemplo, un insumo donado) |
| `estado` | texto | `DISPONIBLE`, `EN_FALTA` o `YA_PEDIDO` (ver abajo) |
| `stock_bajo` | booleano | `true` si el stock sin vencer es el límite configurado o menos (10 si no se cambió) |
| `activo` | booleano | `false` si fue dado de baja |

Los valores de `estado` son códigos. El texto que ve el usuario lo arma el frontend:

| Código | Texto sugerido |
|---|---|
| `DISPONIBLE` | Disponible |
| `EN_FALTA` | En falta |
| `YA_PEDIDO` | Ya pedido |

### Lotes

Desde la migración 029 (26/11) el stock de un insumo está en **lotes**, igual que el de los productos
(`contrato.md`, "Lotes"):

```json
{ "id": 21, "numero": 2, "nombre": "L2 (VS-0911)", "codigo": "VS-0911", "vencimiento": "2027-04-25", "stock": 25, "vencido": false }
```

- `numero`: los lotes de cada insumo se numeran 1, 2, 3… en el orden en que se crean; en la pantalla,
  **L1, L2, L3…** (`nombre`, que suma el número del fabricante, `codigo`, si tiene). No se cambia.
- **`vence`**: si el insumo vence (vacunas, material estéril, líquidos), cada lote nuevo lleva su
  vencimiento; si no vence (guantes, vendas), ningún lote lo lleva, su stock va a un único lote sin
  vencimiento y la pantalla no muestra lotes. Se cambia solo con el stock en 0.
- `lotes` trae los que tienen stock y los vacíos que no vencieron y siguen la regla de `vence`, el que
  vence primero arriba.
- **Las consultas usan lo que no está vencido, empezando por el lote que vence primero**, y siguen con
  el siguiente si no alcanza (`POST /internal/stock/descontar`). Un lote vencido con stock queda para
  retirarlo, y Reportes lo avisa ("Lotes vencidos").
- Los movimientos dicen de qué lote fueron (`lote`).
- La migración dejó a cada insumo que ya existía con un lote L1 con todo su stock, sin vencimiento, y
  marcó que vencen los que tienen `aplicacion` (vacunas y antiparasitarios).

### Estado y stock bajo

- **El estado no se elige: se calcula según el stock.** Si el stock es 0, el insumo queda `EN_FALTA`; si es mayor que 0, queda `DISPONIBLE`. Se actualiza cada vez que cambia el stock: en el alta, en la carga de stock, en el retiro manual y cuando una consulta descuenta insumos.
- **Ya pedido:** con stock bajo, el veterinario puede marcar que ya lo pidió al proveedor (`POST .../pedido`). Queda `YA_PEDIDO` aunque se sigan descontando unidades, para que se vea que el faltante está resuelto y no se pida dos veces. Al cargar stock (llegó el pedido) vuelve a calcularse según el stock.
- El frontend nunca manda `estado` en el alta ni en la edición: si el pedido lo trae, se ignora.
- **Stock bajo:** un insumo con 10 unidades o menos tiene `stock_bajo: true`. (Entrega 5) El 10 es el valor por defecto: la veterinaria lo cambia en Configuración (`limite_stock_bajo`, el mismo que usan los reportes y los productos). La regla está en el módulo (`Insumo::stockBajo()`), así el frontend no la repite: solo lo destaca en el listado. Un insumo en falta (stock 0) también tiene `stock_bajo: true`.

### Validación de campos

Se aplica en el alta (`POST`) y en la edición (`PUT`). Antes de validar se quitan los espacios al principio y al final de los textos. Si un campo llega con un tipo incorrecto (por ejemplo, un texto donde va un número), se considera inválido.

| Campo | Regla | Mensaje si falta | Mensaje si es inválido |
|---|---|---|---|
| `nombre` | Texto de 2 a 120 caracteres | "El nombre es obligatorio" | "El nombre debe tener entre 2 y 120 caracteres" |
| `costo` | Número de 0 a menos de 100.000.000, con hasta 2 decimales | "El costo es obligatorio" | "El costo debe ser un número mayor o igual a 0 con hasta 2 decimales" |
| `stock` | Solo en el alta. Entero de 0 a 1.000.000 | "El stock es obligatorio" | "El stock debe ser un número entero entre 0 y 1.000.000" |
| `vence` | `true` o `false` (029). Obligatorio en el alta; en la edición es opcional (si no viene, no cambia) y solo se puede cambiar con el stock en 0 | "Indicá si vence o no" | "Indicá si vence o no" / "Se puede cambiar solo sin stock: retirá o usá las N unidades primero" |
| `vencimiento` | Solo en el alta: el del primer lote (L1). Fecha `AAAA-MM-DD`, no anterior a hoy. Si `vence` y hay stock inicial, obligatorio; si no vence, tiene que ir vacío | "Este insumo vence: poné la fecha de vencimiento del lote" | "La fecha de vencimiento no es válida" / "La fecha de vencimiento no puede ser anterior a hoy" / "Este insumo no vence: dejá el vencimiento vacío" |
| `lote` | Solo en el alta: el número de lote del fabricante del primer lote. Opcional, hasta 40 caracteres | — | "El número de lote del fabricante puede tener hasta 40 caracteres" |
| `aplicacion` | Opcional (migración 025). Qué vacuna o antiparasitario del calendario sanitario es: `SEXTUPLE`, `TRIPLE_FELINA`, `ANTIRRABICA`, `TOS_PERRERAS`, `LEUCEMIA_FELINA` o `ANTIPARASITARIO`. `null` o `""` = no es del calendario. En la edición, si no viene, no se cambia | — | "Elegí una vacuna o antiparasitario de la lista" |

La consulta de una vacuna o desparasitación tiene que llevar un insumo con su `aplicacion` (Pacientes,
`contrato-consultas.md`). La migración 025 marca los que ya existían por su nombre y crea, con stock 0,
el de cada aplicación que faltaba.

Si hay errores, se responde `400` con un mensaje por campo:

```json
{
  "error": "Datos inválidos",
  "campos": {
    "nombre": "El nombre es obligatorio",
    "costo": "El costo debe ser un número mayor o igual a 0 con hasta 2 decimales"
  }
}
```

Los campos que no figuran en la tabla (por ejemplo `id`, `estado`, `stock_bajo` o `activo`) se ignoran.

### Identificadores en la ruta

Si el `{id}` de la ruta no es un número entero positivo, o no existe un insumo con ese id, se responde `404 { "error": "Insumo no encontrado" }`.

---

## GET /api/inventario/insumos

Listado para el panel del veterinario: **todos** los insumos, incluidos los dados de baja, ordenados por nombre. Sin paginado.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 101,
      "nombre": "Jeringa descartable 3 ml",
      "aplicacion": null,
      "stock": 200,
      "costo": 150.00,
      "estado": "DISPONIBLE",
      "stock_bajo": false,
      "activo": true
    },
    {
      "id": 107,
      "nombre": "Sutura absorbible 3-0",
      "stock": 0,
      "costo": 2500.00,
      "estado": "EN_FALTA",
      "stock_bajo": true,
      "activo": true
    }
  ],
  "limite_stock_bajo": 10
}
```

`limite_stock_bajo` (Entrega 5): el límite configurado, para el filtro "solo stock bajo" del listado.

---

## GET /api/inventario/insumos/{id}

Un insumo con todos sus datos. Lo usa el formulario de edición para precargar los campos.

**200 OK:** el insumo, con los mismos campos que cada elemento del listado.

**Errores:** `404` si no existe.

---

## POST /api/inventario/insumos

Crea un insumo activo. El estado se calcula a partir del stock inicial.

**Pedido:**

```json
{
  "nombre": "Gasa estéril 10 x 10",
  "costo": 180.00,
  "stock": 100,
  "vence": true,
  "vencimiento": "2028-04-09",
  "lote": "GA-1102"
}
```

`vencimiento` y `lote` (el número del fabricante) son los del primer lote, L1 (029): si hay stock, vencimiento o número de lote, se crea ese lote con el stock.

Si `stock` es mayor que 0, se registra también un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO` por esa cantidad, así el stock inicial queda en el historial. El insumo y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**201 Created:** el insumo creado, con el mismo formato que `GET /api/inventario/insumos/{id}`.

**Errores:** `400` si algún campo falta o es inválido (ver [Validación de campos](#validación-de-campos)).

---

## PUT /api/inventario/insumos/{id}

Edita el nombre y el costo (y, si vienen, `aplicacion` y `vence`). **No cambia el stock**: el stock solo cambia con la carga de stock (o cuando una consulta lo descuenta), para que cada variación quede registrada en los movimientos. Si el pedido trae `stock`, se ignora. `vence` (029) se cambia solo con el insumo sin stock (si no, `400` con `campos.vence`).

**Pedido:**

```json
{ "nombre": "Gasa estéril 10 x 10 cm", "costo": 200.00 }
```

Un insumo dado de baja se puede editar, pero sigue dado de baja.

**200 OK:** el insumo actualizado, con el mismo formato que `GET /api/inventario/insumos/{id}`.

**Errores:** `400` si algún campo es inválido; `404` si no existe.

---

## DELETE /api/inventario/insumos/{id}

Baja lógica: el insumo queda con `activo: false` y se conserva su historial (las consultas y los movimientos que lo nombran). No se borra la fila. Un insumo dado de baja no se puede agregar a una consulta.

**204 No Content**, sin cuerpo. Dar de baja un insumo que ya estaba dado de baja también responde `204`.

**Errores:** `404` si no existe.

---

## POST /api/inventario/insumos/{id}/reactivar

Deshace la baja: el insumo vuelve con `activo: true`, con el stock y el historial que tenía. Reactivar un insumo activo no cambia nada.

**200 OK** con el insumo completo (como en `GET /api/inventario/insumos/{id}`). **Errores:** `404` si no existe.

---

## POST /api/inventario/insumos/{id}/pedido · DELETE /api/inventario/insumos/{id}/pedido

`POST` marca el insumo como pedido al proveedor (`estado: "YA_PEDIDO"`); `DELETE` lo desmarca (por si se marcó por error) y el estado vuelve a salir del stock. Repetir cualquiera de los dos no cambia nada. Sin cuerpo.

**200 OK**, igual que la carga de stock:

```json
{ "id": 108, "stock": 3, "estado": "YA_PEDIDO", "stock_bajo": true }
```

**Errores:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `404` | `{ "error": "Insumo no encontrado" }` | No existe |
| `409` | `{ "error": "El insumo está dado de baja" }` | Está dado de baja |
| `409` | `{ "error": "Solo se marca como pedido un insumo con stock bajo (10 o menos)" }` | `POST` con más unidades que el límite (el número del mensaje es el límite configurado) |

---

## POST /api/inventario/insumos/{id}/stock

Suma unidades a un lote y registra un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO`, a nombre del veterinario de la sesión. Si el insumo estaba `EN_FALTA` o `YA_PEDIDO`, pasa a `DISPONIBLE`. El stock, el estado y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido** (029), uno de tres, igual que en productos (`contrato.md`, `POST .../stock`):

```json
{ "cantidad": 10, "lote_id": 21 }
{ "cantidad": 10, "lote": { "codigo": "VS-0911", "vencimiento": "2027-04-25" } }
{ "cantidad": 10 }
```

- `lote_id`: a un lote que ya existe, sin vencer y que siga la regla de `vence`.
- `lote`: a un lote nuevo, con el número que sigue. `vencimiento` es obligatorio si el insumo vence y tiene que ir vacío si no vence.
- Sin ninguno de los dos: solo si el insumo no vence, a su lote sin vencimiento.

**200 OK:**

```json
{ "id": 107, "stock": 10, "estado": "DISPONIBLE", "stock_bajo": true, "stock_sin_vencer": 10, "vencimiento": "2027-04-25",
  "lote": { "id": 21, "numero": 2, "nombre": "L2 (VS-0911)", "codigo": "VS-0911", "vencimiento": "2027-04-25", "stock": 10, "vencido": false } }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000:

  ```json
  {
    "error": "Datos inválidos",
    "campos": { "cantidad": "La cantidad debe ser un número entero entre 1 y 100.000" }
  }
  ```

- `400` con `campos.lote_id` ("Ese lote no es de este insumo", "Ese lote está vencido: cargá en otro o creá uno nuevo", "Ese lote no sigue la regla del insumo…" o "Este insumo vence: elegí un lote o creá uno nuevo con su vencimiento"), o con `campos["lote.codigo"]` o `campos["lote.vencimiento"]` si el lote nuevo es inválido.
- `404`, si el insumo no existe.
- `409`, si el insumo está dado de baja: `{ "error": "El insumo está dado de baja" }`.

---

## PUT /api/inventario/insumos/{id}/lotes/{lote}

(029) Corrige el número del fabricante o el vencimiento de un lote mal cargado: `{ "codigo": "VS-0911", "vencimiento": "2027-04-25" }`, con las mismas reglas que en productos (el vencimiento es obligatorio si el insumo vence, vacío si no; puede ser pasado). No cambia el stock ni el número.

**200 OK:** el insumo completo, como en `GET /api/inventario/insumos/{id}`. **Errores:** `400` (`campos.codigo` o `campos.vencimiento`) y `404` si el insumo no existe o el lote no es suyo.

---

## POST /api/inventario/insumos/{id}/retiro

Resta unidades del stock sin que haya una consulta (por ejemplo, el veterinario se lleva una jeringa para su mascota) y registra un movimiento `SALIDA` con motivo `AJUSTE`, a nombre del veterinario de la sesión. Así toda salida queda registrada, aunque no venga de una consulta. Si el stock llega a 0, el insumo pasa a `EN_FALTA`. El stock, el estado y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido:** `{ "cantidad": 1, "lote_id": 21 }`. Sin `lote_id` (029), sale de los lotes que vencen primero, los vencidos antes que nada.

**200 OK:**

```json
{ "id": 101, "stock": 199, "estado": "DISPONIBLE", "stock_bajo": false, "stock_sin_vencer": 199, "vencimiento": "2028-10-26" }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000 (mismo mensaje que la carga de stock).
- `404`, si el insumo no existe.
- `409`, si el insumo está dado de baja: `{ "error": "El insumo está dado de baja" }`.
- `400` con `campos.lote_id`, si el lote no es de este insumo.
- `409`, si se quiere retirar más de lo que hay (en el lote, si se eligió uno). No se descuenta nada:

  ```json
  { "error": "Stock insuficiente", "stock": 3 }
  ```

---

## GET /api/inventario/insumos/movimientos

Movimientos de **todos** los insumos, del más reciente al más antiguo. Lo usa la pantalla "Movimientos" del panel: al entrar muestra todos, y el selector de insumo funciona como filtro.

**Parámetro (opcional):**

| Parámetro | Descripción |
|---|---|
| `insumo` | Id de un insumo: solo trae sus movimientos. Si no es un id válido, se ignora y se traen todos. Si el insumo no existe o no tiene movimientos, `datos` llega vacío |
| `desde`, `hasta` | (Entrega 5) Fechas `AAAA-MM-DD`, inclusive: solo los movimientos de esos días |

**Ejemplos:** `GET /api/inventario/insumos/movimientos` · `GET /api/inventario/insumos/movimientos?insumo=101&desde=2026-11-01&hasta=2026-11-30`

**200 OK:** el mismo formato que `GET /api/inventario/insumos/{id}/movimientos` (abajo), y además en cada movimiento:

- `consulta_id` y `compra` (Entrega 5): de dónde salió el movimiento, para enlazarlo. `consulta_id` es la consulta en la que se usó (motivo `CONSULTA`); `compra`, el número de la compra de la tienda (`V-000123`, motivos `VENTA` y `VENTA_CANCELADA`). Si no corresponde, `null`. Salen del `id_operacion` del movimiento (`src/ReferenciaMovimiento.php`).

**Errores:** `400 { "error": "Datos inválidos", "campos": { "desde": "..." } }` si una fecha no tiene el formato `AAAA-MM-DD` o `desde` es posterior a `hasta`.

---

## GET /api/inventario/insumos/{id}/movimientos

Historial de movimientos de un insumo, del más reciente al más antiguo.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 45,
      "fecha": "2026-11-06 15:30:00",
      "tipo": "SALIDA",
      "motivo": "CONSULTA",
      "cantidad": 2,
      "insumo_id": 101,
      "insumo": "Jeringa descartable 3 ml",
      "usuario": "Nombre del veterinario"
    },
    {
      "id": 31,
      "fecha": "2026-11-05 10:00:00",
      "tipo": "ENTRADA",
      "motivo": "CARGA_VETERINARIO",
      "cantidad": 50,
      "insumo_id": 101,
      "insumo": "Jeringa descartable 3 ml",
      "usuario": "Nombre del veterinario"
    }
  ]
}
```

- `tipo`: `ENTRADA` o `SALIDA`.
- `motivo`: un código. Los insumos no se venden, así que nunca tienen `VENTA`. El texto que ve el usuario lo arma el frontend:

  | `tipo` | `motivo` | Texto sugerido | Cuándo |
  |---|---|---|---|
  | `ENTRADA` | `CARGA_VETERINARIO` | Carga de stock | Alta con stock inicial o carga de stock |
  | `SALIDA` | `CONSULTA` | Usado en una consulta | Al finalizar una consulta |
  | `SALIDA` | `AJUSTE` | Retiro manual (sin consulta) | Retiro manual |
  | `SALIDA` | `VENCIMIENTO` | Baja por vencimiento | La tarea diaria da de baja un lote vencido (27/11, `contrato.md`, "POST /api/inventario/tareas/vencer-lotes"). Sin usuario |

- `insumo_id` e `insumo`: id y nombre del insumo del movimiento (sirven en la lista de todos los insumos).
- `usuario`: nombre de quien registró el movimiento, o `null` si no lo registró una persona.

**Errores:** `404` si el insumo no existe.

---

## Para los otros módulos

- **Pacientes (consultas):** lee `nombre`, `costo`, `stock`, `estado` y `activo` directo de la tabla `insumo`, con su usuario de MySQL `pacientes` (solo lectura). No usa estos endpoints. (029) `insumo.stock` es la suma de los lotes, vencidos incluidos, e `insumo.fecha_vencimiento` el del próximo lote que vence: los mantiene Inventario.
- **Descuento de stock:** cuando se finaliza una consulta, el stock de los insumos lo descuenta Inventario en `POST /internal/stock/descontar` (contrato interno, rol 1), del lote sin vencer que vence primero (029). Ese descuento también actualiza el estado: si el stock llega a 0, el insumo queda `EN_FALTA` (salvo que esté `YA_PEDIDO`, que se conserva).
