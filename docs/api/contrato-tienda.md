# Contrato de la API — Tienda: compra para retirar (módulo Inventario)

Qué recibe y qué devuelve cada endpoint de la compra en la tienda: el carrito, la confirmación con la factura, el pago, el retiro en el local y los vencimientos. El frontend programa contra este documento. Si un endpoint cambia, este archivo se actualiza en el mismo commit.

El catálogo (ver y buscar productos) está en [contrato.md](contrato.md). Las reglas generales que valen para todos los módulos (formato JSON, formato de errores, cookie de sesión y token CSRF) están en el [contrato del ApiGateway](https://github.com/romeroppau/PAW--ApiGateway--TPIntegrador/blob/main/api/CONTRATO_API_ApiGateway.md) y en el [contrato de autenticación](https://github.com/romeroppau/PAW--Backend--TPIntegrador/blob/main/docs/api/contrato.md).

## Cómo funciona una compra

```
El cliente arma el carrito (agrega, cambia cantidades, quita)        → el stock no se toca
Toca "Ir a pagar" (confirmar)                                        → factura V-… PENDIENTE_PAGO y stock reservado
  ├─ se arrepiente antes de pagar (o no paga en 15 minutos)          → factura CANCELADA y el stock vuelve
  └─ paga con Mercado Pago                                           → factura PAGADA: 5 días hábiles para retirar
       ├─ la retira en el local y la veterinaria lo constata         → factura ENTREGADA
       ├─ la cancela él mismo antes de retirarla                     → factura CANCELADA, sin devolución, y el stock vuelve
       └─ no la retira en 5 días hábiles                             → factura CANCELADA, sin devolución, y el stock vuelve
```

## Resumen

| Método y ruta | Quién | Para qué |
|---|---|---|
| `GET /api/tienda/carrito` | Comprador | Ver su carrito abierto, con el total y los plazos |
| `GET /api/tienda/productos/{id}/imagen` | Público | La foto de un producto, como imagen |
| `POST /api/tienda/carrito/items` | Comprador | Agregar un producto |
| `PUT /api/tienda/carrito/items/{producto_id}` | Comprador | Cambiar la cantidad de un producto |
| `DELETE /api/tienda/carrito/items/{producto_id}` | Comprador | Quitar un producto |
| `POST /api/tienda/carrito/confirmar` | Comprador | Confirmar la compra: crea la factura y reserva el stock |
| `GET /api/tienda/compras` | Comprador | Mis compras |
| `GET /api/tienda/compras/{numero}` | Comprador | Una compra, con su detalle |
| `POST /api/tienda/compras/{numero}/cancelar` | Comprador | Cancelar la compra (antes de pagar o ya pagada, mientras está para retirar) |
| `POST /api/tienda/compras/{numero}/pago` | Comprador | Pagar con Mercado Pago: devuelve a dónde ir a pagar |
| `POST /api/tienda/compras/{numero}/pago/confirmar` | Comprador | Al volver de Mercado Pago: confirma el pago |
| `POST /api/tienda/pagos/aviso` | Mercado Pago | Aviso de pago (webhook, con firma) |
| `POST /api/tienda/pagos/simulado` | Comprador | Pago simulado (solo local y GitHub) |
| `GET /api/inventario/compras` | Veterinario | Pedidos para retirar, entregados y cancelados, con buscador |
| `POST /api/tienda/compras/{numero}/entregada` | Veterinario | Constatar el retiro en el local |
| `POST /api/tienda/tareas/vencer` | GitHub Actions | Cancelar las compras vencidas y mandar los recordatorios |
| `GET /api/tienda/promociones` | Público | Los productos en promoción (sección "Promociones" de la tienda) |
| `GET /api/inventario/promociones` | Veterinario | Las promociones vigentes |
| `POST /api/inventario/promociones` | Veterinario | "Publicitar": poner un descuento a un producto |
| `DELETE /api/inventario/promociones/{id}` | Veterinario | Terminar una promoción |

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

El carrito muestra siempre el **precio de hoy** de cada producto, con el descuento de su promoción si tiene una vigente (ver "Promociones"). Al confirmar se cobra ese mismo precio, y cada ítem de la compra lo guarda (`precio_unitario`): la factura no cambia aunque después cambie el precio del producto o termine la promoción. El total lo calcula siempre el servidor.

(Hasta la Entrega 5 el precio se copiaba al agregar el producto y quedaba fijo. Se cambió con las promociones: si no, un producto agregado durante una promoción se seguía cobrando con descuento después de que terminaba.)

### Plazos

| Plazo | Desde | Si se vence |
|---|---|---|
| **15 minutos para pagar** (configurable) | La confirmación | La compra se cancela, el stock vuelve y el carrito se descarta |
| **5 días hábiles para retirar** (configurable; lunes a viernes, sin contar feriados), hasta el último día inclusive | La confirmación | La compra se cancela **sin devolución del importe** y el stock vuelve |

Por ejemplo, una compra confirmada el miércoles 18/11 se puede retirar hasta el miércoles 25/11 inclusive.

**Los plazos los configura la veterinaria** desde su panel (configuración, grupo "Tienda"; catálogo en Backend, `CatalogoConfiguracion.php`). Lo de arriba son los valores por defecto:

| Parámetro (`configuracion`) | Por defecto | Rango |
|---|---|---|
| `minutos_para_pagar` | 15 | 5 a 60 minutos |
| `dias_habiles_para_retirar` | 5 | 2 a 15 días hábiles |
| `hora_avisos_tienda` | 09:00 | 07:00 a 20:00 (desde esta hora salen los recordatorios y se cancelan los pedidos no retirados) |
| `dia_primer_recordatorio` | 2 | 1 a 13 (al menos 2 días hábiles antes del último día) |

**Cada compra guarda sus plazos al confirmarse** (`factura_compra.pagar_antes_de` y `retirar_hasta`, migración 015). Un cambio en la configuración vale solo para las compras **nuevas**: a las que ya existen no se les cambia lo prometido. Si una compra no los tiene guardados (anterior a la migración), se calculan con los valores por defecto. La hora de los avisos y el día del primer recordatorio se leen al momento de mandar cada aviso.

**Política de cancelación** (se muestra al confirmar la compra y en el primer recordatorio): *tenés 5 días hábiles desde la compra para retirar tu pedido en el local. Si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra. Si cancelás un pedido ya pagado, el importe tampoco se reintegra.*

No hay devoluciones: una compra pagada termina entregada, cancelada por el comprador o vencida, y en ningún caso se devuelve el importe. El comprador puede cancelarla mientras está para retirar (hasta que se entrega o vence el plazo); el stock vuelve enseguida. La veterinaria no cancela compras.

### Estados

| Factura | El carrito queda | Cuándo |
|---|---|---|
| `PENDIENTE_PAGO` | `CONFIRMADO` | Se confirmó y todavía no se pagó |
| `PAGADA` | `PAGADO` | Mercado Pago aprobó el pago |
| `ENTREGADA` | `RETIRADO` | La veterinaria constató el retiro |
| `CANCELADA` | `CANCELADO` | La canceló el comprador, no se pagó en 15 minutos o venció el plazo de retiro (ver `motivo_cancelacion`) |

Texto sugerido para el frontend: `PENDIENTE_PAGO` → "Esperando el pago", `PAGADA` → "Lista para retirar", `ENTREGADA` → "Entregada", `CANCELADA` → "Cancelada".

Para la gente, un pedido "confirmado" es uno **pagado** ("Lista para retirar"). Por eso el botón del carrito que llama a `confirmar` dice **"Ir a pagar"**: confirmar solo aparta el stock mientras se paga. `PENDIENTE_PAGO` es ese momento intermedio (como mucho 15 minutos) y la veterinaria nunca lo ve en su pantalla.

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
      "precio_sin_descuento": null,
      "descuento": null,
      "cantidad": 2,
      "subtotal": 31999.80,
      "stock": 20,
      "a_la_venta": true,
      "tiene_imagen": true
    }
  ],
  "total": 31999.80,
  "minutos_para_pagar": 15,
  "dias_habiles_para_retirar": 5,
  "retirar_hasta_si_compra_hoy": "2026-11-25"
}
```

- `precio_unitario`: el precio de hoy, con el descuento si el producto está en promoción.
- `precio_sin_descuento` y `descuento`: si está en promoción, el precio sin descuento y el porcentaje (para mostrarlo tachado); si no, `null`.
- `stock`: lo que hay disponible hoy, para que la pantalla no ofrezca más.
- `a_la_venta`: `false` si el producto se dio de baja o venció después de agregarlo. La pantalla avisa que hay que quitarlo: así no se puede confirmar.
- `tiene_imagen`: si el producto tiene foto. La foto se pide aparte, con `GET /api/tienda/productos/{id}/imagen`.
- `minutos_para_pagar`, `dias_habiles_para_retirar` y `retirar_hasta_si_compra_hoy` (con la configuración de hoy): para explicar los plazos antes de pagar ("tenés 15 minutos para pagar" y "retirás hasta el miércoles 25/11"). Vienen también con el carrito vacío.

## GET /api/tienda/productos/{id}/imagen

Público. La foto de un producto **como imagen** (no dentro de un JSON), para usarla directo en `<img src="/api/tienda/productos/101/imagen">`. Responde con su tipo (`image/png`, `image/jpeg` o `image/webp`). El navegador la guarda, pero antes de usarla pregunta si cambió (`Cache-Control: no-cache` y `ETag`): si es la misma, `304` sin la foto; si la veterinaria la cambió, la nueva. La usan el catálogo y el carrito. Funciona aunque el producto ya no esté a la venta (el carrito puede tener uno que se dio de baja).

**Errores:** `404 { "error": "Imagen no encontrada" }` si el producto no existe o no tiene foto.

---

## POST /api/tienda/carrito/items

Agrega un producto al carrito. Si el comprador no tenía carrito abierto, se crea. Si el producto ya estaba, se suma la cantidad. El precio es siempre el de hoy (ver "Precios").

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
  "pagar_antes_de": "2026-11-18 10:45:00",
  "segundos_para_pagar": 900,
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
      "pagar_antes_de": null,
      "segundos_para_pagar": null
    }
  ]
}
```

- `pagar_antes_de`: solo en `PENDIENTE_PAGO`; en los demás estados, `null`.
- `motivo_cancelacion`: solo en `CANCELADA` (si no, `null`): `COMPRADOR` (la canceló el comprador, antes o después de pagar), `SIN_PAGO` (no se pagó en 15 minutos) o `VENCIDA` (venció el plazo de retiro). Texto sugerido: "La canceló el comprador", "No se pagó a tiempo", "Venció el plazo de retiro".
- `dias_habiles_para_retirar`: los días hábiles de plazo que tuvo esta compra (para el texto de la política).
- `segundos_para_pagar`: solo en `PENDIENTE_PAGO` (si no, `null`): cuántos segundos faltan para que venza, contados por el servidor. La cuenta regresiva de la pantalla parte de este número, así no depende del reloj de la computadora del comprador.
- `retirar_hasta`: en `PENDIENTE_PAGO` y `PAGADA`; en los demás estados, `null`.

---

## GET /api/tienda/compras/{numero}

Una compra con sus productos. Mismo formato que la respuesta de confirmar, más `entregada_en` (`null` si todavía no se retiró) y `operacion_mp` (el número de operación de Mercado Pago del pago aprobado, el comprobante; `null` si no se pagó).

**Errores:** `404 { "error": "Compra no encontrada" }` si no existe o es de otro comprador.

---

## POST /api/tienda/compras/{numero}/cancelar

El comprador cancela su compra:

- **antes de pagar** (`PENDIENTE_PAGO`): arrepentimiento;
- **ya pagada** (`PAGADA`), mientras está para retirar: el importe **no se reintegra** y se le manda el aviso "cancelada por el cliente" (ver Avisos).

La factura pasa a `CANCELADA` con `motivo_cancelacion = COMPRADOR`, el carrito a `CANCELADO` y el stock vuelve (`ENTRADA` / `VENTA_CANCELADA`).

**200 OK:** la compra actualizada.

**Errores:**

- `404` si no existe o es de otro comprador.
- `409 { "error": "La compra ya se entregó: no se puede cancelar" }`, o `409 { "error": "La compra ya está cancelada" }` (también si venció el plazo de retiro: se cancela sola).

---

## Pago con Mercado Pago

Checkout Pro (API de preferencias), **siempre con credenciales de prueba**: nunca se cobra de verdad, ni en producción. Inventario recibe `MP_ACCESS_TOKEN` (del vendedor de prueba), `MP_WEBHOOK_SECRET` (firma de los avisos) y `SITIO_URL` (la dirección pública del sitio).

```
"Ir a pagar" → POST confirmar (compra, 15 min) → POST …/pago → el comprador va a Mercado Pago
   ├─ vuelve a tienda/compra.html?numero=V-…&payment_id=…&status=… → POST …/pago/confirmar
   └─ Mercado Pago avisa por su cuenta → POST /api/tienda/pagos/aviso
```

La compra pasa a pagada solo cuando **nuestro servidor consulta el pago a Mercado Pago** (por la vuelta o por el aviso, el que llegue primero; `pago.id_externo` es único, así que el otro no repite nada). Nunca se cree lo que dice la dirección de vuelta ni el aviso.

**Qué se registra según el pago:**

| Pago | Compra | Se registra el pago como | Aviso al comprador |
|---|---|---|---|
| Aprobado, compra esperando el pago | `PAGADA` | `APROBADO` | — |
| Aprobado, compra ya cancelada (pago tardío) y **hay stock** | `PAGADA` otra vez (el stock se reserva de nuevo, movimiento `revive-V-…`) | `APROBADO` | el mismo de un pago a tiempo: "Recibimos el pago de tu pedido…" (al cliente no le hace falta saber que llegó tarde) |
| Aprobado, compra cancelada **sin stock**, ya pagada con otro pago o por otro monto | sin cambios | `DEVUELTO` (se pide la devolución a Mercado Pago) | según el caso: "Tu pago del pedido V-… llegó cuando ya había terminado el tiempo para pagar y, mientras tanto, otra persona compró los productos que habías elegido y no quedó stock, así que te devolvimos el dinero." o "Tu pedido V-… ya estaba pagado, así que te devolvimos el pago que hiciste de más." |
| Rechazado | sigue `PENDIENTE_PAGO` (puede reintentar mientras le quede tiempo) | `RECHAZADO` | — |
| Pendiente o en proceso | sin cambios (se espera el próximo aviso) | — | — |

La preferencia: los productos de la compra, `external_reference` = el número de la compra, vence **1 minuto antes** que la compra (`expiration_date_to` = `pagar_antes_de` − 1 minuto: un pago que empezó a tiempo tiene ese minuto para terminar antes de que la compra se cancele, así casi no hay pagos tardíos), **sin efectivo** (`ticket` y `atm` excluidos), **una sola cuota**, `binary_mode` (aprobado o rechazado al instante), vuelta a `tienda/compra.html?numero=V-…` y aviso a `/api/tienda/pagos/aviso`.

## POST /api/tienda/compras/{numero}/pago

Comprador, con CSRF. Crea el pago y devuelve a dónde va el comprador.

**200 OK:** `{ "url": "https://www.mercadopago.com.ar/checkout/..." }`. En local y en GitHub (`ENTORNO` = `local` o `ci`): `{ "url": "/tienda/pago-simulado.html?numero=V-…" }`.

**Errores:** `404` si no existe o es de otro comprador; `409 { "error": "La compra no está esperando el pago" }` (ya pagada, cancelada o vencidos los 15 minutos); `409 { "error": "Ya no queda tiempo para pagar esta compra" }` si queda menos de 1 minuto (el link vencería enseguida); `503` si Mercado Pago no está configurado; `502` si Mercado Pago no respondió.

## POST /api/tienda/compras/{numero}/pago/confirmar

Comprador, con CSRF. Lo llama la página de la compra al volver de Mercado Pago, con el número de pago que Mercado Pago puso en la dirección de vuelta:

```json
{ "payment_id": "123456789" }
```

Consulta ese pago a Mercado Pago, lo registra (tabla de arriba) y devuelve **la compra actualizada** (como `GET /api/tienda/compras/{numero}`). Sin `payment_id`, devuelve la compra tal como está.

**Errores:** `404` si no existe, es de otro comprador o el pago es de otra compra; `502` si Mercado Pago no respondió.

## POST /api/tienda/pagos/aviso

Lo llama Mercado Pago (webhook): `?type=payment&data.id=123456789`, con los headers `x-signature` y `x-request-id`.

1. Verifica la firma: HMAC-SHA256 con `MP_WEBHOOK_SECRET` sobre `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`. Si no coincide: `401`.
2. Si no es un pago (`type` distinto de `payment`): `200 { "resultado": "ignorado" }`.
3. Consulta el pago a Mercado Pago y lo registra (tabla de arriba): `200 { "resultado": "aprobado" }` (o `revivido`, `devolver`, `rechazado`, `repetido`, `ignorado`, `no_existe`).
4. Si Mercado Pago no conoce ese pago (por ejemplo, el aviso de prueba de "Simular notificación" del panel): `200 { "resultado": "no_existe" }`.
5. Si Mercado Pago no responde: `500`, y Mercado Pago reintenta el aviso más tarde.

## POST /api/tienda/pagos/simulado

**Solo en local y en GitHub** (en dev y producción: `404`). Comprador, con CSRF. Hace lo mismo que un pago de Mercado Pago, sin Mercado Pago. Sirve también para probar un pago tardío.

```json
{ "numero": "V-000123", "resultado": "aprobado" }
```

`resultado`: `aprobado` o `rechazado` (si no: `400`). **200 OK:** la compra actualizada, más `"resultado"` (como en el aviso).

---

## GET /api/inventario/compras

Los pedidos de la veterinaria, en tres pestañas: **Para retirar** (`PAGADA`), **Entregados** (`ENTREGADA`) y **Cancelados** (`CANCELADA`). Las compras `PENDIENTE_PAGO` no aparecen en ninguna: para la veterinaria todavía no son pedidos.

**Parámetros (opcionales):**

- `estado` = `PAGADA` (por defecto), `ENTREGADA` o `CANCELADA`. Un valor desconocido se ignora y se usa `PAGADA`.
- `buscar`: el buscador, dentro de la pestaña elegida. Encuentra por **número de pedido** (por los dígitos del final: `101`, `V-101` y `V-000101` encuentran `V-000101`) o por **nombre del comprador** (cualquier parte, sin importar mayúsculas). Hasta 100 caracteres.

**Orden:** Para retirar, del que vence antes al que vence después. Entregados (por fecha de entrega) y Cancelados (por fecha de cancelación), los más recientes primero (son historial). En Cancelados, cada pedido trae `motivo_cancelacion`.

**200 OK:**

```json
{
  "datos": [
    {
      "numero": "V-000003",
      "estado": "PAGADA",
      "fecha": "2026-11-18 10:30:00",
      "total": 31999.80,
      "pagar_antes_de": null,
      "segundos_para_pagar": null,
      "retirar_hasta": "2026-11-25",
      "comprador": "Ana Pérez",
      "items": [ { "nombre": "Alimento cachorro 3 kg", "cantidad": 2 } ]
    }
  ]
}
```

En la pestaña Entregados, cada pedido trae además `entregada_en` (`"2026-11-20 16:05:00"`) y `entregada_por` (el nombre de la veterinaria que lo entregó).

## POST /api/tienda/compras/{numero}/entregada

La veterinaria constata que el comprador retiró el pedido en el local. Solo para compras `PAGADA`. La factura pasa a `ENTREGADA` (con la fecha y la veterinaria que lo constató) y el carrito a `RETIRADO`.

**200 OK:** la compra actualizada.

**Errores:** `404` si no existe; `409 { "error": "La compra no está lista para retirar" }` si no está `PAGADA`.

---

## POST /api/tienda/tareas/vencer

La llama GitHub Actions cada 15 minutos, con el header `X-Tarea-Token`. Hace tres cosas:

1. cancela las compras `PENDIENTE_PAGO` con más de 15 minutos (el stock vuelve);
2. cancela las compras `PAGADA` cuyo plazo de retiro ya pasó, sin devolución (el stock vuelve);
3. manda los recordatorios del día (ver Avisos).

Las mismas cancelaciones se hacen también **al pasar**: cada vez que se usa la tienda (el carrito, las compras del comprador, los pedidos de la veterinaria y "entregada"), primero se cancelan las vencidas. Así nunca se muestra stock reservado de más, aunque la tarea se atrase. Al pasar no se mandan avisos: el de cancelación lo manda la próxima corrida de la tarea, ese mismo día.

Las cancelaciones por falta de pago salen a cualquier hora. Las de vencimiento del plazo de retiro y los avisos, desde las 9:00 (hora de Argentina). Si Backend no recibe un aviso, la cancelación se hace igual y el aviso se reintenta en las corridas siguientes del mismo día.

**200 OK:**

```json
{ "canceladas_sin_pago": 1, "canceladas_vencidas": 0, "avisos": 2 }
```

`avisos` cuenta los avisos nuevos de esta corrida (los que ya se habían mandado no se cuentan).

**Errores:** `401` sin el token o con otro.

## Promociones

La veterinaria pone un descuento a un producto desde las sugerencias de Reportes ("Qué liquidar" → **Publicitar**): el producto aparece en la sección **Promociones** de la tienda y en el catálogo con el precio rebajado, y el carrito y la compra cobran ese precio. Tabla `promocion` (migración 016).

- El descuento es un **porcentaje entero de 5 a 90**. El precio con descuento se calcula con el precio del producto de ese momento, redondeado a centavos.
- Dura **hasta el día `hasta` inclusive**: desde hoy, como mucho 90 días y nunca después del vencimiento del producto.
- **Una sola promoción vigente por producto.** Para cambiarla, se termina y se publica otra.
- Vigente = no se terminó y `hasta` es hoy o después. Si el producto se da de baja, vence o se queda sin stock, la promoción sigue vigente pero no aparece en la sección de promociones (en el catálogo, sin stock se ve como siempre: sin "Agregar").
- Las promociones no se borran: terminar una guarda cuándo.

### GET /api/tienda/promociones

Público. Los productos en promoción que se pueden comprar (activos, no vencidos y con stock), del descuento más grande al más chico. Mismo formato que el catálogo (`contrato.md`), sin paginar.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 105,
      "nombre": "Antiparasitario gatos",
      "categoria": "MEDICAMENTO",
      "especie": "GATO",
      "precio": 9800.00,
      "vencimiento": "2026-12-10",
      "disponible": true,
      "promocion": { "porcentaje": 30, "precio": 6860.00, "hasta": "2026-12-10" }
    }
  ]
}
```

### GET /api/inventario/promociones

Veterinario. Las promociones vigentes, de la que termina antes a la que termina después.

**200 OK:**

```json
{
  "datos": [
    {
      "id": 3,
      "producto_id": 105,
      "nombre": "Antiparasitario gatos",
      "precio": 9800.00,
      "porcentaje": 30,
      "precio_promocion": 6860.00,
      "hasta": "2026-12-10",
      "stock": 4,
      "vencimiento": "2026-12-10",
      "a_la_venta": true,
      "creada_en": "2026-11-20 18:05:00",
      "creada_por": "Vet"
    }
  ]
}
```

`a_la_venta`: `false` si el producto se dio de baja o venció (la promoción no se ve en la tienda).

### POST /api/inventario/promociones

Veterinario, con CSRF. "Publicitar".

**Pedido:**

```json
{ "producto_id": 105, "porcentaje": 30, "hasta": "2026-12-10" }
```

**201 Created:** la promoción, con el formato de `GET /api/inventario/promociones`.

**Errores:**

- `400 { "error": "Datos inválidos", "campos": { … } }`:
  - `porcentaje`: "El descuento tiene que ser un número entero entre 5 y 90 (por ciento)";
  - `hasta`: "Elegí hasta qué día dura la promoción", "La promoción no puede terminar antes de hoy", "La promoción puede durar como mucho 90 días (hasta el dd/mm/aaaa)" o "El producto vence el dd/mm/aaaa: la promoción no puede durar más que eso".
- `404 { "error": "Producto no encontrado" }`.
- `409 { "error": "El producto no está a la venta (está dado de baja o vencido)" }`.
- `409 { "error": "Este producto ya tiene una promoción del 30 % hasta el 10/12/2026. Terminala para publicar otra.", "promocion_id": 3 }`.

### DELETE /api/inventario/promociones/{id}

Veterinario, con CSRF. Termina la promoción ahora: deja de verse en la tienda y el carrito vuelve al precio sin descuento.

**204 No Content.** **Errores:** `404 { "error": "No hay una promoción vigente con ese número" }` (no existe, ya terminó o ya pasó su fecha).

---

## Avisos al comprador y a la veterinaria

Se mandan por `POST /internal/notificaciones` (Backend), con tipo `COMPRA` y por los dos canales (la campana del sitio y WhatsApp). Cada aviso lleva un `id_operacion` fijo, así correr la tarea varias veces no lo repite. Como Backend no acepta dos notificaciones con el mismo `id_operacion`, cada canal agrega el suyo al final: `compra-V-000003-dia2-web` y `compra-V-000003-dia2-whatsapp`.

**Al pagar** (enseguida, a cualquier hora). Al confirmar ("Ir a pagar") no se avisa: el comprador ya está yendo a Mercado Pago.

| Cuándo | A quién | Canales | `id_operacion` | Mensaje (ejemplo) |
|---|---|---|---|---|
| Paga (también un pago tardío que se pudo usar) | comprador | campana y WhatsApp (constancia) | `compra-V-000003-pagada` | ✅ ¡Hola, Ana! Recibimos el pago de tu pedido V-000003. Ya podés retirarlo en HuellaVet hasta el miércoles 25/11 inclusive. ¡Te esperamos! |
| Paga (también un pago tardío que se pudo usar) | **cada veterinaria** (todos los usuarios con rol `VETERINARIO`) | campana | `compra-V-000003-pagada-vet-101` (uno por veterinaria) | 🛍️ Nuevo pedido para retirar: V-000003 de Ana Pérez ($ 31.999,80). Fecha límite de retiro: miércoles 25/11. |

Si Backend no responde, la compra sigue igual: el aviso no frena la venta (el error queda en el log).

**Recordatorios y cancelaciones** (la tarea programada): cada aviso sale solo en su día, desde las 9:00.

| Cuándo | `id_operacion` | Mensaje (ejemplo) |
|---|---|---|
| Primer recordatorio: el día hábil configurado (por defecto, el 2) de una compra `PAGADA` | `compra-V-000003-dia2` | 🐾 ¡Hola, Ana! Tu pedido V-000003 ya te está esperando en HuellaVet. Podés pasar a buscarlo hasta el miércoles 25/11 inclusive. ¡Te esperamos! 🛍️ Política de cancelación: si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra. |
| Último recordatorio: el día hábil anterior al último día para retirar | `compra-V-000003-dia4` | ⏰ ¡Hola, Ana! Te recordamos que el miércoles 25/11 es el último día para retirar tu pedido V-000003. Pasado ese día, el pedido se cancela y el importe no se reintegra. |
| Cuando el comprador cancela un pedido pagado (enseguida; si falla, la tarea lo reintenta ese día) | `compra-V-000003-cancelada-por-cliente` | Hola, Ana. Cancelaste tu pedido V-000003 y los productos volvieron a la tienda. Como indica la política de cancelación, el importe no se reintegra. Si tenés alguna duda, escribinos. ¡Te esperamos pronto en HuellaVet! 🐾 |
| Al cancelarse por vencimiento | `compra-V-000003-cancelada` | Hola, Ana. Tu pedido V-000003 se canceló porque venció el plazo de retiro (25/11). Como te avisamos, el importe no se reintegra. Si tenés alguna duda, escribinos. 🐾 |

Cada mensaje va en un solo renglón (WhatsApp no acepta saltos de línea en la plantilla).
