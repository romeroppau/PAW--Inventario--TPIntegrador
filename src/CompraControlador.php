<?php

// Los endpoints de las compras de la tienda (contrato en docs/api/contrato-tienda.md):
// confirmar el carrito, mis compras, una compra y cancelarla (antes de pagar o ya pagada).
// Los usa el comprador (CLIENTE o RESCATISTA), salvo los pedidos para retirar y "entregada", que
// son de la veterinaria. El usuario sale siempre del JWT.
class CompraControlador
{
    private const ROLES_COMPRADOR = ['CLIENTE', 'RESCATISTA'];
    private const NO_ENCONTRADA = 'Compra no encontrada';
    // Las pestañas de la veterinaria. PENDIENTE_PAGO no está: para ella todavía no es un pedido.
    private const PESTANIAS = ['PAGADA', 'ENTREGADA', 'CANCELADA'];
    private const BUSCAR_MAXIMO = 100;
    // Las listas vienen de a 20 ("Ver más" pide la página siguiente).
    private const POR_PAGINA = 20;
    // Sin fechas, las listas de historial muestran los últimos 30 días (hoy incluido).
    private const DIAS_POR_DEFECTO = 30;

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

    // GET /api/tienda/compras?desde=2026-10-22&hasta=2026-11-20&pagina=1
    // Sin fechas: los últimos 30 días.
    public static function misCompras(): void
    {
        $comprador = self::comprador();
        $filtro = self::filtro(Request::query(), true);
        if ($filtro === null) {
            return;
        }

        [$compras, $hayMas] = self::repositorio()->delComprador(
            (int) $comprador['id'], $filtro['desde'], $filtro['hasta'], $filtro['pagina'], self::POR_PAGINA
        );

        Response::json(self::pagina($compras, $hayMas, $filtro));
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

    // GET /api/tienda/compras/{numero}/factura
    // La factura de una compra pagada (aunque después se haya cancelado). La ve su comprador y la
    // veterinaria. Una compra que no se llegó a pagar no tiene factura: 404.
    public static function factura(array $parametros): void
    {
        $sesion = Auth::requerirRol([...self::ROLES_COMPRADOR, 'VETERINARIO']);
        Vencimientos::alPasar();

        $compradorId = $sesion['rol'] === 'VETERINARIO' ? null : (int) $sesion['id'];
        $factura = self::repositorio()->factura((string) ($parametros['numero'] ?? ''), $compradorId);
        if ($factura === null) {
            Response::json(['error' => 'Factura no encontrada'], 404);
            return;
        }

        Response::json($factura);
    }

    // POST /api/tienda/compras/{numero}/cancelar
    // El comprador cancela su compra: antes de pagar o ya pagada (mientras está para retirar).
    // Si estaba pagada, el importe no se reintegra y se le manda un aviso.
    public static function cancelar(array $parametros): void
    {
        $comprador = self::comprador(true);
        $compras = self::repositorio();
        $numero = (string) ($parametros['numero'] ?? '');

        $resultado = $compras->cancelarPorComprador($numero, (int) $comprador['id']);

        if ($resultado === 'no_existe') {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
            return;
        }
        if ($resultado === 'entregada') {
            Response::json(['error' => 'La compra ya se entregó: no se puede cancelar'], 409);
            return;
        }
        if ($resultado === 'cancelada') {
            Response::json(['error' => 'La compra ya está cancelada'], 409);
            return;
        }
        if ($resultado === 'pagada') {
            (new Vencimientos($compras))->avisarCancelacionPorComprador($numero, new DateTimeImmutable());
        }

        Response::json($compras->detalle($numero));
    }

    // GET /api/inventario/compras?estado=PAGADA&buscar=101&vence=hoy&desde=…&hasta=…&pagina=1
    // (veterinaria). Para retirar: todos o, con vence=hoy, los de último día hoy. Entregados y
    // cancelados: sin fechas, los últimos 30 días.
    public static function deLaVeterinaria(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        Vencimientos::alPasar();
        $query = Request::query();

        // Un estado desconocido se ignora: se muestran los pagados (los que hay que entregar).
        $estado = $query['estado'] ?? '';
        if (!in_array($estado, self::PESTANIAS, true)) {
            $estado = 'PAGADA';
        }

        // El buscador (número de pedido o nombre del comprador), dentro de la pestaña.
        $buscar = is_string($query['buscar'] ?? null) ? trim($query['buscar']) : '';
        $buscar = mb_substr($buscar, 0, self::BUSCAR_MAXIMO);

        // Los pedidos para retirar no se filtran por fecha (todos están vigentes).
        $filtro = self::filtro($query, $estado !== 'PAGADA');
        if ($filtro === null) {
            return;
        }

        [$pedidos, $hayMas] = self::repositorio()->deLaVeterinaria(
            $estado, $buscar, $filtro['desde'], $filtro['hasta'], ($query['vence'] ?? '') === 'hoy',
            $filtro['pagina'], self::POR_PAGINA
        );

        Response::json(self::pagina($pedidos, $hayMas, $filtro));
    }

    // GET /api/inventario/compras/resumen   (veterinaria) -> { "para_retirar": 7, "vencen_hoy": 2 }
    // Para la pestaña "Para retirar", que arranca sin la lista.
    public static function resumenDeLaVeterinaria(): void
    {
        Auth::requerirRol(['VETERINARIO']);
        Vencimientos::alPasar();

        Response::json(self::repositorio()->conteoParaRetirar());
    }

    // POST /api/tienda/compras/{numero}/entregada   (veterinaria: el comprador retiró el pedido)
    public static function entregada(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);
        // Si el plazo de retiro ya venció, la compra se cancela acá y no se puede entregar.
        Vencimientos::alPasar();
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

        // Antes de mirar el carrito o las compras, se cancelan las vencidas (stock al día).
        Vencimientos::alPasar();

        return $sesion;
    }

    // Lee pagina y, si $conFechas, desde y hasta ("AAAA-MM-DD"; sin ellas, los últimos 30 días).
    // Si algo está mal, responde 400 y devuelve null.
    private static function filtro(array $query, bool $conFechas): ?array
    {
        $errores = [];

        $pagina = $query['pagina'] ?? '1';
        if (!is_string($pagina) || !preg_match('/^[1-9]\d{0,5}$/', $pagina)) {
            $errores['pagina'] = 'Tiene que ser un número entero desde 1';
        }

        $desde = null;
        $hasta = null;
        if ($conFechas) {
            $hoy = new DateTimeImmutable('today');
            $hasta = self::fecha($query['hasta'] ?? null, $hoy, 'hasta', $errores);
            $desde = self::fecha($query['desde'] ?? null, ($hasta ?? $hoy)->modify('-' . (self::DIAS_POR_DEFECTO - 1) . ' days'), 'desde', $errores);
            if ($desde !== null && $hasta !== null && $desde > $hasta) {
                $errores['desde'] = 'Tiene que ser antes o igual que "hasta"';
            }
        }

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return null;
        }

        return [
            'pagina' => (int) $pagina,
            'desde' => $desde?->format('Y-m-d'),
            'hasta' => $hasta?->format('Y-m-d'),
        ];
    }

    // Una fecha "AAAA-MM-DD" del query; si no vino (o vino vacía), $defecto. Si es inválida, anota el error.
    private static function fecha(mixed $valor, DateTimeImmutable $defecto, string $campo, array &$errores): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return $defecto;
        }

        $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
        if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
            $errores[$campo] = 'Tiene que ser una fecha AAAA-MM-DD';
            return null;
        }

        return $fecha;
    }

    // La respuesta de una lista paginada.
    private static function pagina(array $datos, bool $hayMas, array $filtro): array
    {
        return [
            'datos' => $datos,
            'pagina' => $filtro['pagina'],
            'por_pagina' => self::POR_PAGINA,
            'hay_mas' => $hayMas,
            'desde' => $filtro['desde'],
            'hasta' => $filtro['hasta'],
        ];
    }

    private static function repositorio(): CompraRepositorio
    {
        return new CompraRepositorio(Database::conectar());
    }
}
