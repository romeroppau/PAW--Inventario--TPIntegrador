# Contrato de la API — Tienda: compra para retirar (módulo Inventario)

Qué recibe y qué devuelve cada endpoint de la compra en la tienda: el carrito, la confirmación con la factura, el pago, el retiro en el local y los vencimientos. El frontend programa contra este documento. Si un endpoint cambia, este archivo se actualiza en el mismo commit.

El catálogo (ver y buscar productos) está en [contrato.md](contrato.md). Las reglas generales que valen para todos los módulos (formato JSON, formato de errores, cookie de sesión y token CSRF) están en el [contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md) y en el [contrato de autenticación](https://github.com/romeroppau/PAW--Backend--TPIntegrador/blob/main/docs/api/contrato.md).

## Cómo funciona una compra

```
El cliente arma el carrito (agrega, cambia cantidades, quita)        → el stock no se toca
Confirma                                                             → factura V-… PENDIENTE_PAGO y stock reservado
  ├─ se arrepiente antes de pagar (o no paga en 30 minutos)          → factura CANCELADA y el stock vuelve
  └─ paga con Mercado Pago                                           → factura PAGADA: 5 días hábiles para retirar
       ├─ la retira en el local y la veterinaria lo constata         → factura ENTREGADA
       └─ no la retira en 5 días hábiles                             → factura CANCELADA, sin devolución, y el stock vuelve
```

## Resumen

| Método y ruta | Quién | Para qué |
|---|---|---|
| `GET /api/tienda/carrito` | Comprador | Ver su carrito abierto, con el total |
| `POST /api/tienda/carrito/items` | Comprador | Agregar un producto |
| `PUT /api/tienda/carrito/items/{producto_id}` | Comprador | Cambiar la cantidad de un producto |
| `DELETE /api/tienda/carrito/items/{producto_id}` | Comprador | Quitar un producto |
| `POST /api/tienda/carrito/confirmar` | Comprador | Confirmar la compra: crea la factura y reserva el stock |
| `GET /api/tienda/compras` | Comprador | Mis compras |
| `GET /api/tienda/compras/{numero}` | Comprador | Una compra, con su detalle |
| `POST /api/tienda/compras/{numero}/cancelar` | Comprador | Arrepentirse antes de pagar |
| `POST /api/tienda/compras/{numero}/pago` | Comprador | Pagar con Mercado Pago *(etapa 3)* |
| `POST /api/tienda/pagos/aviso` | Mercado Pago | Aviso de pago *(etapa 3)* |
| `GET /api/inventario/compras` | Veterinario | Pedidos para retirar |
| `POST /api/tienda/compras/{numero}/entregada` | Veterinario | Constatar el retiro en el local |
| `POST /api/tienda/tareas/vencer` | GitHub Actions | Cancelar las compras vencidas y mandar los recordatorios |

**Comprador** = un usuario con rol `CLIENTE` o `RESCATISTA` (cualquier rescatista, aunque todavía no esté aprobado: la aprobación es para publicar en adopciones, no para comprar).

## Reglas generales

### Acceso

- Las rutas del comprador exigen la cookie `huellavet_sesion` de un usuario `CLIENTE` o `RESCATISTA`. Las de la veterinaria, de un `VETERINARIO`.
  - `401 { "error": "No autenticado" }` si no hay sesión o venció.
  - `403 { "error": "No tiene permisos para realizar esta operación" }` si el rol es otro.
- `POST`, `PUT` y `DELETE` exigen además el header `X-CSRF-Token`. Si falta o no coincide: `403 { "error": "Token CSRF inválido" }`.
- El comprador sale siempre del JWT de la cookie, nunca de un dato del pedido. Un comprador solo ve y toca su carrito y sus compras: la compra de otro responde `404`, igual que si no existiera.
- `POST /api/tienda/tareas/vencer` no usa sesión: exige el header `X-Tarea-Token` con el token de las tareas programadas. Sin el token, o con otro: `401 { "error": "No autorizado" }`.

### Qué productos se pueden comprar

Los mismos que se ven en el catálogo: **activos y no vencidos**. Si un producto deja de estar a la venta (se da de baja o vence), no se puede agregar al carrito ni confirmar una compra que lo tenga: hay que quitarlo.

### Stock

- **Armar el carrito no toca el stock.** Pero no se puede agregar ni pedir más unidades de las que hay: no hay otro tope que el stock.
- **Al confirmar, el stock se reserva:** se resta y queda un movimiento `SALIDA` con motivo `VENTA`. Se vuelve a controlar en ese momento, en una sola transacción: si un producto ya no alcanza (otro lo compró mientras tanto), no se reserva nada.
- **Cuando una compra se cancela** (arrepentimiento, falta de pago o vencimiento), el stock vuelve: movimiento `ENTRADA` con motivo `VENTA_CANCELADA`.

### Precios

El precio de cada producto se copia **al agregarlo al carrito** (`precio_unitario`). El total lo calcula siempre el servidor.

### Plazos

| Plazo | Desde | Si se vence |
|---|---|---|
| **30 minutos para pagar** | La confirmación | La compra se cancela, el stock vuelve y el carrito se descarta |
| **5 días hábiles para retirar** (lunes a viernes, sin contar feriados), hasta el último día inclusive | La confirmación | La compra se cancela **sin devolución del importe** y el stock vuelve |

Por ejemplo, una compra confirmada el miércoles 18/11 se puede retirar hasta el miércoles 25/11 inclusive.

**Política de cancelación** (se muestra al confirmar la compra y en el primer recordatorio): *tenés 5 días hábiles desde la compra para retirar tu pedido en el local. Si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra.*

No hay devoluciones: una compra pagada solo termina entregada o vencida. La veterinaria no cancela compras.

### Estados

| Factura | El carrito queda | Cuándo |
|---|---|---|
| `PENDIENTE_PAGO` | `CONFIRMADO` | Se confirmó y todavía no se pagó |
| `PAGADA` | `PAGADO` | Mercado Pago aprobó el pago |
| `ENTREGADA` | `RETIRADO` | La veterinaria constató el retiro |
| `CANCELADA` | `CANCELADO` | Arrepentimiento, falta de pago o vencimiento del plazo de retiro |

Texto sugerido para el frontend: `PENDIENTE_PAGO` → "Pendiente de pago", `PAGADA` → "Lista para retirar", `ENTREGADA` → "Entregada", `CANCELADA` → "Cancelada".

### Número de factura

`V-` seguido del id con 6 dígitos: `V-000001`. Las facturas de consulta (Pacientes) empiezan con `C-`.

### Formatos

- Importes: números con hasta 2 decimales (`15999.90`), igual que en el catálogo.
- Fechas: texto `AAAA-MM-DD HH:MM:SS` en hora de Argentina. Las fechas límite de retiro, `AAAA-MM-DD`.

---

## GET /api/tienda/carrito

El carrito abierto del comprador. Si todavía no tiene uno, responde un carrito vacío (no se crea hasta que agrega el primer producto).

**200 OK:**

```json
{
  "items": [
    {
      "producto_id": 101,
      "nombre": "Alimento cachorro 3 kg",
      "precio_unitario": 15999.90,
      "cantidad": 2,
      "subtotal": 31999.80,
      "stock": 20,
      "a_la_venta": true
    }
  ],
  "total": 31999.80
}
```

- `stock`: lo que hay disponible hoy, para que la pantalla no ofrezca más.
- `a_la_venta`: `false` si el producto se dio de baja o venció después de agregarlo. La pantalla avisa que hay que quitarlo: así no se puede confirmar.

---

## POST /api/tienda/carrito/items

Agrega un producto al carrito. Si el comprador no tenía carrito abierto, se crea. Si el producto ya estaba, se suma la cantidad (el precio queda el de la primera vez).

**Pedido:**

```json
{ "producto_id": 101, "cantidad": 2 }
```

**200 OK:** el carrito, con el mismo formato que `GET /api/tienda/carrito`.

**Errores:**

- `400`, si la cantidad no es un entero mayor que 0:
  ```json
  { "error": "Datos inválidos", "campos": { "cantidad": "La cantidad debe ser un número entero mayor que 0" } }
  ```
- `404 { "error": "Producto no encontrado" }`, si no existe o no está a la venta (dado de baja o vencido).
- `409`, si la cantidad (sumada a la que ya estaba en el carrito) supera el stock:
  ```json
  { "error": "Stock insuficiente", "stock": 3 }
  ```

---

## PUT /api/tienda/carrito/items/{producto_id}

Cambia la cantidad de un producto del carrito (los botones + y −).

**Pedido:**

```json
{ "cantidad": 3 }
```

**200 OK:** el carrito.

**Errores:** `400` si la cantidad no es un entero mayor que 0 (para sacarlo se usa `DELETE`); `404` si el producto no está en el carrito; `409 "Stock insuficiente"` si supera el stock.

---

## DELETE /api/tienda/carrito/items/{producto_id}

Quita un producto del carrito.

**200 OK:** el carrito (puede quedar vacío).

**Errores:** `404` si el producto no está en el carrito.

---

## POST /api/tienda/carrito/confirmar

Confirma la compra. En una sola transacción:

1. controla que cada producto siga a la venta y que alcance el stock;
2. reserva el stock (movimiento `SALIDA` / `VENTA` por producto, con un `id_operacion` propio);
3. crea la factura `V-…` en `PENDIENTE_PAGO`, con la fecha de hoy;
4. el carrito pasa a `CONFIRMADO` (y el comprador puede empezar otro).

Si algo falla, no se reserva nada.

**201 Created:**

```json
{
  "numero": "V-000003",
  "estado": "PENDIENTE_PAGO",
  "fecha": "2026-11-18 10:30:00",
  "total": 31999.80,
  "pagar_antes_de": "2026-11-18 11:00:00",
  "retirar_hasta": "2026-11-25",
  "items": [
    { "producto_id": 101, "nombre": "Alimento cachorro 3 kg", "precio_unitario": 15999.90, "cantidad": 2, "subtotal": 31999.80 }
  ]
}
```

**Errores:**

- `409 { "error": "El carrito está vacío" }`.
- `409`, si un producto ya no está a la venta o no alcanza el stock. Se informan todos los que tienen problemas:
  ```json
  {
    "error": "Stock insuficiente",
    "items": [ { "producto_id": 101, "nombre": "Alimento cachorro 3 kg", "pedido": 2, "stock": 1 } ]
  }
  ```

---

## GET /api/tienda/compras

Mis compras, de la más nueva a la más vieja (las canceladas también).

**200 OK:**

```json
{
  "datos": [
    {
      "numero": "V-000003",
      "estado": "PAGADA",
      "fecha": "2026-11-18 10:30:00",
      "total": 31999.80,
      "retirar_hasta": "2026-11-25",
      "pagar_antes_de": null
    }
  ]
}
```

- `pagar_antes_de`: solo en `PENDIENTE_PAGO`; en los demás estados, `null`.
- `retirar_hasta`: en `PENDIENTE_PAGO` y `PAGADA`; en los demás estados, `null`.

---

## GET /api/tienda/compras/{numero}

Una compra con sus productos. Mismo formato que la respuesta de confirmar, más `entregada_en` (`null` si todavía no se retiró).

**Errores:** `404 { "error": "Compra no encontrada" }` si no existe o es de otro comprador.

---

## POST /api/tienda/compras/{numero}/cancelar

Arrepentimiento: el comprador cancela una compra **que todavía no pagó**. La factura pasa a `CANCELADA`, el carrito a `CANCELADO` y el stock vuelve (`ENTRADA` / `VENTA_CANCELADA`).

**200 OK:** la compra actualizada.

**Errores:**

- `404` si no existe o es de otro comprador.
- `409 { "error": "La compra ya está pagada: no se puede cancelar" }` si ya se pagó (no hay devoluciones), o `409 { "error": "La compra ya está cancelada" }`.

---

## POST /api/tienda/compras/{numero}/pago *(etapa 3)*

Crea el pago en Mercado Pago (preferencia, con la factura como referencia y las direcciones de vuelta y de aviso) y devuelve la dirección a la que se redirige al comprador.

**200 OK:** `{ "url": "<dirección de pago de Mercado Pago>" }`

**Errores:** `404` si no existe o es de otro comprador; `409` si no está en `PENDIENTE_PAGO` (ya pagada, cancelada o vencidos los 30 minutos).

## POST /api/tienda/pagos/aviso *(etapa 3)*

Lo llama Mercado Pago cuando cambia el estado de un pago. No se cree lo que dice el aviso: se verifica su firma, se consulta el pago a Mercado Pago y se compara el monto con la factura. `pago.id_externo` es único: un aviso repetido no se registra dos veces.

- Pago aprobado: pago `APROBADO`, factura `PAGADA`, carrito `PAGADO`.
- Pago rechazado: pago `RECHAZADO`, factura `CANCELADA`, y el stock vuelve.
- Si algo falla al procesarlo, responde `500` y Mercado Pago lo reintenta.

El detalle se completa en la etapa 3.

---

## GET /api/inventario/compras

Pedidos para retirar, de la veterinaria. Por defecto trae los `PAGADA` (los que hay que entregar), ordenados por fecha límite de retiro.

**Parámetro (opcional):** `estado` = `PENDIENTE_PAGO`, `PAGADA`, `ENTREGADA` o `CANCELADA`. Un valor desconocido se ignora.

**200 OK:**

```json
{
  "datos": [
    {
      "numero": "V-000003",
      "estado": "PAGADA",
      "fecha": "2026-11-18 10:30:00",
      "total": 31999.80,
      "retirar_hasta": "2026-11-25",
      "comprador": "Ana Pérez",
      "items": [ { "nombre": "Alimento cachorro 3 kg", "cantidad": 2 } ]
    }
  ]
}
```

## POST /api/tienda/compras/{numero}/entregada

La veterinaria constata que el comprador retiró el pedido en el local. Solo para compras `PAGADA`. La factura pasa a `ENTREGADA` (con la fecha y la veterinaria que lo constató) y el carrito a `RETIRADO`.

**200 OK:** la compra actualizada.

**Errores:** `404` si no existe; `409 { "error": "La compra no está lista para retirar" }` si no está `PAGADA`.

---

## POST /api/tienda/tareas/vencer

La llama GitHub Actions cada 15 minutos, con el header `X-Tarea-Token`. Hace tres cosas:

1. cancela las compras `PENDIENTE_PAGO` con más de 30 minutos (el stock vuelve);
2. cancela las compras `PAGADA` cuyo plazo de retiro ya pasó, sin devolución (el stock vuelve);
3. manda los recordatorios del día (ver Avisos).

Las mismas cancelaciones se hacen también **al pasar**: cada vez que se usa la tienda (ver el carrito, agregar, confirmar, ver las compras), primero se cancelan las vencidas. Así nunca se muestra stock reservado de más, aunque la tarea se atrase.

**200 OK:**

```json
{ "canceladas_sin_pago": 1, "canceladas_vencidas": 0, "avisos": 2 }
```

**Errores:** `401` sin el token o con otro.

## Avisos al comprador

Se mandan por `POST /internal/notificaciones` (Backend), con tipo `COMPRA` y por los dos canales (la campana del sitio y WhatsApp). Cada aviso lleva un `id_operacion` fijo, así correr la tarea varias veces no lo repite.

| Cuándo | `id_operacion` | Mensaje (ejemplo) |
|---|---|---|
| Día hábil 2 de una compra `PAGADA` | `compra-V-000003-dia2` | 🐾 ¡Hola, Ana! Tu pedido V-000003 ya te está esperando en HuellaVet. Podés pasar a buscarlo hasta el miércoles 25/11 inclusive. ¡Te esperamos! 🛍️ Política de cancelación: si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra. |
| Día hábil 4 | `compra-V-000003-dia4` | ⏰ ¡Hola, Ana! Te recordamos que mañana (25/11) es el último día para retirar tu pedido V-000003. Pasado ese día, el pedido se cancela y el importe no se reintegra. |
| Al cancelarse por vencimiento | `compra-V-000003-cancelada` | Hola, Ana. Tu pedido V-000003 se canceló porque venció el plazo de retiro (25/11). Como te avisamos, el importe no se reintegra. Si tenés alguna duda, escribinos. 🐾 |

Cada mensaje va en un solo renglón (WhatsApp no acepta saltos de línea en la plantilla).
