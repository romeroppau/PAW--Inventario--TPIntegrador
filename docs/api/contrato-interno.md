# Contrato interno — Descuento de stock (módulo Inventario)

Endpoint de proceso a proceso, usado por Pacientes al finalizar una consulta. **No es público**: Nginx bloquea `/internal/` desde afuera y además exige un token. El frontend nunca lo llama.

## POST /internal/stock/descontar

`http://127.0.0.1:9002/internal/stock/descontar` (sin pasar por Nginx)

**Header obligatorio:** `X-Servicio-Token: <INTERNAL_TOKEN>`

**Pedido:**

```json
{
  "usuario_id": 3,
  "items": [
    { "tipo": "PRODUCTO", "id": 7, "cantidad": 2, "id_operacion": "c0a8...-0001" },
    { "tipo": "INSUMO", "id": 3, "cantidad": 1, "id_operacion": "c0a8...-0002" }
  ]
}
```

| Campo | Regla |
|---|---|
| `usuario_id` | Entero mayor que 0. El veterinario que finaliza la consulta |
| `items` | Lista de 1 a 50 ítems |
| `tipo` | `PRODUCTO` o `INSUMO` |
| `id` | Entero mayor que 0 |
| `cantidad` | Entero mayor que 0 |
| `id_operacion` | Texto de hasta 64 caracteres, único por ítem. No se puede repetir dentro del mismo pedido |

Los tipos tienen que ser los correctos: un número como texto (`"2"`) o un decimal (`2.5`) se rechaza.

**Qué hace:** todo o nada, en una sola transacción.
- Resta el stock de cada ítem y registra un movimiento `SALIDA` con motivo `CONSULTA` por ítem. Los movimientos de insumo llevan solo `insumo_id`; los de producto, solo `producto_id`.
- En los insumos también actualiza el estado: si el stock llega a 0, queda `EN_FALTA`; si no, `DISPONIBLE`.
- No filtra por `activo`: un ítem dado de baja después de cargarse en la consulta igual se descuenta. Pacientes valida `activo` al **agregar** el ítem.

**Respuestas:**

| Código | Cuerpo | Cuándo |
|---|---|---|
| `200` | `{ "aplicado": true }` | Todo descontado |
| `200` | `{ "aplicado": false }` | Ya se había aplicado (`id_operacion` repetido). No descuenta de nuevo |
| `400` | `{ "error": "Datos de solicitud inválidos" }` | Cuerpo mal formado |
| `401` | `{ "error": "No autorizado" }` | Falta el token o no coincide |
| `404` | `{ "error": "Ítem no encontrado", "items": [ { "tipo": "INSUMO", "id": 3 } ] }` | El producto o insumo no existe. No descuenta nada |
| `409` | `{ "error": "Stock insuficiente", "items": [ { "tipo": "INSUMO", "id": 3, "stock": 0 } ] }` | Algún ítem no alcanza. No descuenta nada. Lista todos los faltantes |
| `500` | `{ "error": "No se pudo procesar el descuento de stock" }` | Error inesperado, sin detalles internos |

## Reintentos

`id_operacion` identifica a cada ítem. Pacientes lo arma siempre igual a partir de la consulta y el ítem, así que un doble clic o un reintento tras un fallo manda los mismos valores y no descuenta dos veces. Reintentar es seguro.