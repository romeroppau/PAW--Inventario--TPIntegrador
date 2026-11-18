<?php

// Los endpoints del carrito de la tienda (contrato en docs/api/contrato-tienda.md).
// Los usa el comprador: un CLIENTE o un RESCATISTA con sesión. El comprador sale siempre del JWT.
class CarritoControlador
{
    private const ROLES_COMPRADOR = ['CLIENTE', 'RESCATISTA'];
    private const ERROR_CANTIDAD = 'La cantidad debe ser un número entero mayor que 0';
    private const CANTIDAD_MAXIMA = 1000000;   // nunca hay tanto stock: evita números absurdos

    // GET /api/tienda/carrito
    public static function ver(): void
    {
        $comprador = self::comprador();

        Response::json(self::repositorio()->contenido((int) $comprador['id']));
    }

    // POST /api/tienda/carrito/items   { "producto_id": 101, "cantidad": 2 }
    public static function agregar(): void
    {
        $comprador = self::comprador(true);
        $datos = Request::json();

        $productoId = self::entero($datos['producto_id'] ?? null);
        if ($productoId === null) {
            Response::json(['error' => 'Producto no encontrado'], 404);
            return;
        }

        $cantidad = self::cantidad($datos);
        if ($cantidad === null) {
            return;
        }

        $carrito = self::repositorio();
        $resultado = $carrito->agregar((int) $comprador['id'], $productoId, $cantidad);

        if (!self::responderError($resultado)) {
            Response::json($carrito->contenido((int) $comprador['id']));
        }
    }

    // PUT /api/tienda/carrito/items/{producto_id}   { "cantidad": 3 }
    public static function cambiar(array $parametros): void
    {
        $comprador = self::comprador(true);

        $productoId = self::entero($parametros['producto_id'] ?? null);
        if ($productoId === null) {
            Response::json(['error' => 'El producto no está en el carrito'], 404);
            return;
        }

        $cantidad = self::cantidad(Request::json());
        if ($cantidad === null) {
            return;
        }

        $carrito = self::repositorio();
        $resultado = $carrito->cambiar((int) $comprador['id'], $productoId, $cantidad);

        if (!self::responderError($resultado)) {
            Response::json($carrito->contenido((int) $comprador['id']));
        }
    }

    // DELETE /api/tienda/carrito/items/{producto_id}
    public static function quitar(array $parametros): void
    {
        $comprador = self::comprador(true);

        $productoId = self::entero($parametros['producto_id'] ?? null);
        $carrito = self::repositorio();

        if ($productoId === null || !$carrito->quitar((int) $comprador['id'], $productoId)) {
            Response::json(['error' => 'El producto no está en el carrito'], 404);
            return;
        }

        Response::json($carrito->contenido((int) $comprador['id']));
    }

    // ---------- Ayudas ----------

    // Exige sesión de comprador (401 sin sesión, 403 con otro rol) y, si cambia datos, el token CSRF (403).
    // Devuelve los datos del JWT (id, rol, csrf).
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

    // La cantidad del pedido: entero mayor que 0. Si no lo es, responde 400 y devuelve null.
    private static function cantidad(array $datos): ?int
    {
        $cantidad = $datos['cantidad'] ?? null;

        if (!is_int($cantidad) || $cantidad < 1 || $cantidad > self::CANTIDAD_MAXIMA) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['cantidad' => self::ERROR_CANTIDAD]], 400);
            return null;
        }

        return $cantidad;
    }

    // Traduce el resultado del repositorio a la respuesta de error. Devuelve true si respondió un error.
    private static function responderError(string|array $resultado): bool
    {
        if ($resultado === 'ok') {
            return false;
        }
        if ($resultado === 'no_esta') {
            Response::json(['error' => 'El producto no está en el carrito'], 404);
        } elseif ($resultado === 'no_a_la_venta') {
            Response::json(['error' => 'Producto no encontrado'], 404);
        } else {
            // ['stock_insuficiente', stock]
            Response::json(['error' => 'Stock insuficiente', 'stock' => $resultado[1]], 409);
        }

        return true;
    }

    // Un id entero positivo (de la ruta o del JSON), o null si no lo es.
    private static function entero(mixed $valor): ?int
    {
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (is_string($valor) && ctype_digit($valor) && strlen($valor) <= 10 && (int) $valor > 0) {
            return (int) $valor;
        }

        return null;
    }

    private static function repositorio(): CarritoRepositorio
    {
        return new CarritoRepositorio(Database::conectar());
    }
}
