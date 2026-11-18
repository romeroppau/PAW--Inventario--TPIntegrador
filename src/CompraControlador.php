<?php

// Los endpoints de las compras de la tienda (contrato en docs/api/contrato-tienda.md):
// confirmar el carrito, mis compras, una compra y el arrepentimiento antes de pagar.
// Los usa el comprador (CLIENTE o RESCATISTA), salvo los pedidos para retirar y "entregada", que
// son de la veterinaria. El usuario sale siempre del JWT.
class CompraControlador
{
    private const ROLES_COMPRADOR = ['CLIENTE', 'RESCATISTA'];
    private const NO_ENCONTRADA = 'Compra no encontrada';
    // Las pestañas de la veterinaria. PENDIENTE_PAGO no está: para ella todavía no es un pedido.
    private const PESTANIAS = ['PAGADA', 'ENTREGADA', 'CANCELADA'];
    private const BUSCAR_MAXIMO = 100;

    // POST /api/tienda/carrito/confirmar
    public static function confirmar(): void
    {
        $comprador = self::comprador(true);
        $compras = self::repositorio();

        $resultado = $compras->confirmar((int) $comprador['id']);

        if ($resultado === 'vacio') {
            Response::json(['error' => 'El carrito está vacío'], 409);
            return;
        }
        if (is_array($resultado)) {
            // ['stock_insuficiente', [problemas]]: se informan todos los productos con problemas.
            Response::json(['error' => 'Stock insuficiente', 'items' => $resultado[1]], 409);
            return;
        }

        Response::json($compras->detalle($resultado), 201);
    }

    // GET /api/tienda/compras
    public static function misCompras(): void
    {
        $comprador = self::comprador();

        Response::json(['datos' => self::repositorio()->delComprador((int) $comprador['id'])]);
    }

    // GET /api/tienda/compras/{numero}
    public static function ver(array $parametros): void
    {
        $comprador = self::comprador();

        $compra = self::repositorio()->detalle((string) ($parametros['numero'] ?? ''), (int) $comprador['id']);
        if ($compra === null) {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
            return;
        }

        Response::json($compra);
    }

    // POST /api/tienda/compras/{numero}/cancelar   (arrepentimiento antes de pagar)
    public static function cancelar(array $parametros): void
    {
        $comprador = self::comprador(true);
        $compras = self::repositorio();
        $numero = (string) ($parametros['numero'] ?? '');

        $resultado = $compras->cancelarSinPagar($numero, (int) $comprador['id']);

        if ($resultado === 'no_existe') {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
        } elseif ($resultado === 'pagada') {
            Response::json(['error' => 'La compra ya está pagada: no se puede cancelar'], 409);
        } elseif ($resultado === 'cancelada') {
            Response::json(['error' => 'La compra ya está cancelada'], 409);
        } else {
            Response::json($compras->detalle($numero));
        }
    }

    // GET /api/inventario/compras?estado=PAGADA&buscar=101   (veterinaria: pedidos para retirar)
    public static function deLaVeterinaria(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        $query = Request::query();

        // Un estado desconocido se ignora: se muestran los pagados (los que hay que entregar).
        $estado = $query['estado'] ?? '';
        if (!in_array($estado, self::PESTANIAS, true)) {
            $estado = 'PAGADA';
        }

        // El buscador (número de pedido o nombre del comprador), dentro de la pestaña.
        $buscar = is_string($query['buscar'] ?? null) ? trim($query['buscar']) : '';
        $buscar = mb_substr($buscar, 0, self::BUSCAR_MAXIMO);

        Response::json(['datos' => self::repositorio()->deLaVeterinaria($estado, $buscar)]);
    }

    // POST /api/tienda/compras/{numero}/entregada   (veterinaria: el comprador retiró el pedido)
    public static function entregada(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);
        $compras = self::repositorio();
        $numero = (string) ($parametros['numero'] ?? '');

        $resultado = $compras->marcarEntregada($numero, (int) $sesion['id']);

        if ($resultado === 'no_existe') {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
        } elseif ($resultado === 'no_lista') {
            Response::json(['error' => 'La compra no está lista para retirar'], 409);
        } else {
            Response::json($compras->detalle($numero));
        }
    }

    // ---------- Ayudas ----------

    // Exige sesión de comprador (401 o 403) y, si cambia datos, el token CSRF (403).
    private static function comprador(bool $conCsrf = false): array
    {
        $sesion = Auth::requerirRol(self::ROLES_COMPRADOR);

        if ($conCsrf) {
            Auth::verificarCsrf($sesion);
        }

        return $sesion;
    }

    private static function repositorio(): CompraRepositorio
    {
        return new CompraRepositorio(Database::conectar());
    }
}
