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
| `POST /api/inventario/insumos/{id}/stock` | Veterinario | Cargar stock. Registra un movimiento de entrada |
| `POST /api/inventario/insumos/{id}/retiro` | Veterinario | Retirar unidades sin una consulta. Registra un movimiento de salida |
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
| `stock` | número | Unidades disponibles |
| `costo` | número | Lo que le cuesta a la veterinaria, en pesos, con hasta 2 decimales. Puede ser 0 (por ejemplo, un insumo donado) |
| `estado` | texto | `DISPONIBLE` o `EN_FALTA`. Se calcula solo (ver abajo) |
| `stock_bajo` | booleano | `true` si el stock es 10 o menos |
| `activo` | booleano | `false` si fue dado de baja |

Los valores de `estado` son códigos. El texto que ve el usuario lo arma el frontend:

| Código | Texto sugerido |
|---|---|
| `DISPONIBLE` | Disponible |
| `EN_FALTA` | En falta |

### Estado y stock bajo

- **El estado no se elige: se calcula según el stock.** Si el stock es 0, el insumo queda `EN_FALTA`; si es mayor que 0, queda `DISPONIBLE`. Se actualiza cada vez que cambia el stock: en el alta, en la carga de stock, en el retiro manual y cuando una consulta descuenta insumos.
- El frontend nunca manda `estado`: si el pedido lo trae, se ignora.
- **Stock bajo:** un insumo con 10 unidades o menos tiene `stock_bajo: true`. La regla está en el módulo (`Insumo::stockBajo()`), así el frontend no la repite: solo lo destaca en el listado. Un insumo en falta (stock 0) también tiene `stock_bajo: true`.

### Validación de campos

Se aplica en el alta (`POST`) y en la edición (`PUT`). Antes de validar se quitan los espacios al principio y al final de los textos. Si un campo llega con un tipo incorrecto (por ejemplo, un texto donde va un número), se considera inválido.

| Campo | Regla | Mensaje si falta | Mensaje si es inválido |
|---|---|---|---|
| `nombre` | Texto de 2 a 120 caracteres | "El nombre es obligatorio" | "El nombre debe tener entre 2 y 120 caracteres" |
| `costo` | Número de 0 a menos de 100.000.000, con hasta 2 decimales | "El costo es obligatorio" | "El costo debe ser un número mayor o igual a 0 con hasta 2 decimales" |
| `stock` | Solo en el alta. Entero de 0 a 1.000.000 | "El stock es obligatorio" | "El stock debe ser un número entero entre 0 y 1.000.000" |

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
  ]
}
```

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
  "stock": 100
}
```

Si `stock` es mayor que 0, se registra también un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO` por esa cantidad, así el stock inicial queda en el historial. El insumo y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**201 Created:** el insumo creado, con el mismo formato que `GET /api/inventario/insumos/{id}`.

**Errores:** `400` si algún campo falta o es inválido (ver [Validación de campos](#validación-de-campos)).

---

## PUT /api/inventario/insumos/{id}

Edita el nombre y el costo. **No cambia el stock**: el stock solo cambia con la carga de stock (o cuando una consulta lo descuenta), para que cada variación quede registrada en los movimientos. Si el pedido trae `stock`, se ignora.

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

## POST /api/inventario/insumos/{id}/stock

Suma unidades al stock y registra un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO`, a nombre del veterinario de la sesión. Si el insumo estaba `EN_FALTA`, pasa a `DISPONIBLE`. El stock, el estado y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido:**

```json
{ "cantidad": 10 }
```

**200 OK:**

```json
{ "id": 107, "stock": 10, "estado": "DISPONIBLE", "stock_bajo": true }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000:

  ```json
  {
    "error": "Datos inválidos",
    "campos": { "cantidad": "La cantidad debe ser un número entero entre 1 y 100.000" }
  }
  ```

- `404`, si el insumo no existe.
- `409`, si el insumo está dado de baja: `{ "error": "El insumo está dado de baja" }`.

---

## POST /api/inventario/insumos/{id}/retiro

Resta unidades del stock sin que haya una consulta (por ejemplo, el veterinario se lleva una jeringa para su mascota) y registra un movimiento `SALIDA` con motivo `AJUSTE`, a nombre del veterinario de la sesión. Así toda salida queda registrada, aunque no venga de una consulta. Si el stock llega a 0, el insumo pasa a `EN_FALTA`. El stock, el estado y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido:**

```json
{ "cantidad": 1 }
```

**200 OK:**

```json
{ "id": 101, "stock": 199, "estado": "DISPONIBLE", "stock_bajo": false }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000 (mismo mensaje que la carga de stock).
- `404`, si el insumo no existe.
- `409`, si el insumo está dado de baja: `{ "error": "El insumo está dado de baja" }`.
- `409`, si se quiere retirar más de lo que hay. No se descuenta nada:

  ```json
  { "error": "Stock insuficiente", "stock": 3 }
  ```

---

## GET /api/inventario/insumos/{id}/movimientos

Historial de movimientos de un insumo, del más reciente al más antiguo. Lo usa la pantalla "Movimientos" del panel.

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
      "usuario": "Nombre del veterinario"
    },
    {
      "id": 31,
      "fecha": "2026-11-05 10:00:00",
      "tipo": "ENTRADA",
      "motivo": "CARGA_VETERINARIO",
      "cantidad": 50,
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

- `usuario`: nombre de quien registró el movimiento, o `null` si no lo registró una persona.

**Errores:** `404` si el insumo no existe.

---

## Para los otros módulos

- **Pacientes (consultas):** lee `nombre`, `costo`, `stock`, `estado` y `activo` directo de la tabla `insumo`, con su usuario de MySQL `pacientes` (solo lectura). No usa estos endpoints.
- **Descuento de stock:** cuando se finaliza una consulta, el stock de los insumos lo descuenta Inventario en `POST /internal/stock/descontar` (contrato interno, rol 1). Ese descuento también actualiza el estado: si el stock llega a 0, el insumo queda `EN_FALTA`.
