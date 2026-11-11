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
| `POST /api/inventario/productos/{id}/stock` | Veterinario | Cargar stock. Registra un movimiento de entrada |
| `GET /api/inventario/productos/{id}/movimientos` | Veterinario | Historial de movimientos de un producto |
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
| `stock` | número | Unidades disponibles (solo en las rutas del veterinario) |
| `vencimiento` | texto o `null` | Fecha `AAAA-MM-DD`. `null` si el producto no vence |
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
| `vencimiento` | Opcional. Fecha válida `AAAA-MM-DD`. En el alta no puede ser anterior a hoy. Vacío o `null`: sin vencimiento | — | "La fecha de vencimiento no es válida" / "La fecha de vencimiento no puede ser anterior a hoy" |
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

Catálogo público. Devuelve solo productos **activos** y **no vencidos** (sin vencimiento o con vencimiento de hoy en adelante), ordenados por nombre. Los productos sin stock también aparecen, con `disponible: false`.

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
      "disponible": true
    }
  ],
  "pagina": 1,
  "por_pagina": 12,
  "total": 1
}
```

`total` es la cantidad de productos que cumplen los filtros, sumando todas las páginas.

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
  "imagen": "data:image/jpeg;base64,/9j/4AAQSkZJRg..."
}
```

**Errores:** `404 { "error": "Producto no encontrado" }` si no existe, está dado de baja o está vencido.

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
      "vencimiento": null,
      "activo": true
    }
  ]
}
```

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
  "vencimiento": null,
  "activo": true,
  "imagen": "data:image/jpeg;base64,/9j/4AAQSkZJRg..."
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
  "vencimiento": "2027-03-31",
  "imagen": "data:image/png;base64,iVBORw0KGgo..."
}
```

`descripcion`, `vencimiento` e `imagen` son opcionales.

Si `stock` es mayor que 0, se registra también un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO` por esa cantidad, así el stock inicial queda en el historial.

**201 Created:** el producto creado, con el mismo formato que `GET /api/inventario/productos/{id}`.

**Errores:** `400` si algún campo falta o es inválido (ver [Validación de campos](#validación-de-campos)).

---

## PUT /api/inventario/productos/{id}

Edita los datos de un producto. Se mandan todos los campos del alta **menos `stock`**: el stock solo cambia con la carga de stock, para que cada variación quede registrada en los movimientos. Si el pedido trae `stock`, se ignora.

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

Deshace la baja: el producto vuelve con `activo: true`, con su stock y su historial, y reaparece en el catálogo (si no está vencido). Reactivar un producto activo no cambia nada.

**200 OK** con el producto completo (como en `GET /api/inventario/productos/{id}`). **Errores:** `404` si no existe.

---

## POST /api/inventario/productos/{id}/stock

Suma unidades al stock y registra un movimiento `ENTRADA` con motivo `CARGA_VETERINARIO`, a nombre del veterinario de la sesión. El stock y el movimiento se guardan juntos: si falla uno, no se guarda ninguno.

**Pedido:**

```json
{ "cantidad": 10 }
```

**200 OK:**

```json
{ "id": 7, "stock": 24 }
```

**Errores:**

- `400`, si la cantidad no es un entero entre 1 y 100.000:

  ```json
  {
    "error": "Datos inválidos",
    "campos": { "cantidad": "La cantidad debe ser un número entero entre 1 y 100.000" }
  }
  ```

- `404`, si el producto no existe.
- `409`, si el producto está dado de baja: `{ "error": "El producto está dado de baja" }`.

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

**Errores:** `404` si el producto no existe.

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
