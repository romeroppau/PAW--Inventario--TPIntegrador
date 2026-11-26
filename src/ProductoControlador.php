<?php

// Los endpoints de productos: un método por endpoint (contrato en docs/api/contrato.md).
// Cada método lee el pedido, usa las otras clases y responde con Response::json (de comun/).
// Los métodos son static para conectarlos al router así: [ProductoControlador::class, 'catalogo'].
// El router les pasa los parámetros de la ruta, por ejemplo ['id' => '7'].
class ProductoControlador
{
    private const POR_PAGINA = 12;
    private const NO_ENCONTRADO = 'Producto no encontrado';

    // ---------- Tienda (público) ----------

    // GET /api/tienda/productos?buscar=&categoria=&especie=&pagina=
    public static function catalogo(): void
    {
        $query = Request::query();

        // Los filtros son opcionales. Un valor que no corresponde (por ejemplo, una categoría
        // que no existe, o un parámetro repetido que PHP convierte en lista) se ignora.
        $buscar = is_string($query['buscar'] ?? null) ? trim($query['buscar']) : '';
        $buscar = $buscar === '' ? null : mb_substr($buscar, 0, 100, 'UTF-8');

        $categoria = $query['categoria'] ?? null;
        $categoria = in_array($categoria, ValidadorProducto::CATEGORIAS, true) ? $categoria : null;

        $especie = $query['especie'] ?? null;
        $especie = in_array($especie, ValidadorProducto::ESPECIES, true) ? $especie : null;

        $pagina = filter_var($query['pagina'] ?? 1, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100000, 'default' => 1]
        ]);

        [$productos, $total] = self::repositorio()->listarTienda(
            $buscar,
            $categoria,
            $especie,
            $pagina,
            self::POR_PAGINA
        );

        Response::json([
            'datos' => $productos,
            'pagina' => $pagina,
            'por_pagina' => self::POR_PAGINA,
            'total' => $total
        ]);
    }

    // GET /api/tienda/productos/{id}
    public static function detalleTienda(array $parametros): void
    {
        $id = self::id($parametros);
        $producto = $id === null ? null : self::repositorio()->buscarEnTienda($id);

        if ($producto === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        Response::json($producto);
    }

    // ---------- Panel del veterinario ----------

    // GET /api/inventario/productos
    public static function listado(): void
    {
        self::veterinario();

        Response::json([
            'datos' => self::repositorio()->listarTodos(),
            // El mismo límite que los insumos, para el filtro "solo stock bajo" (Entrega 5).
            'limite_stock_bajo' => Insumo::limiteStockBajo(),
        ]);
    }

    // GET /api/inventario/productos/{id}
    public static function detalle(array $parametros): void
    {
        self::veterinario();

        $id = self::id($parametros);
        $producto = $id === null ? null : self::repositorio()->buscarPorId($id);

        if ($producto === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        Response::json($producto);
    }

    // POST /api/inventario/productos
    public static function crear(): void
    {
        $sesion = self::veterinario(true);

        [$limpios, $errores] = ValidadorProducto::alta(Request::json());

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $productos = self::repositorio();
        $id = $productos->insertar($limpios, (int) $sesion['id']);

        Response::json($productos->buscarPorId($id), 201);
    }

    // PUT /api/inventario/productos/{id}
    public static function editar(array $parametros): void
    {
        self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);

        // Primero se comprueba que exista: si no, 404 aunque los datos también sean inválidos.
        $actual = $id === null ? null : $productos->vence($id);
        if ($actual === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        [$limpios, $errores] = ValidadorProducto::edicion(Request::json());
        // Si vence o no (029) se cambia solo sin stock: si no, sus lotes no seguirían la regla.
        if (isset($limpios['vence']) && $limpios['vence'] !== $actual['vence'] && $actual['stock'] > 0) {
            $errores['vence'] = self::cambioDeVence($actual['stock']);
        }

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $productos->actualizar($id, $limpios);

        Response::json($productos->buscarPorId($id));
    }

    // DELETE /api/inventario/productos/{id}
    public static function darDeBaja(array $parametros): void
    {
        self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);

        if ($id === null || !$productos->existe($id)) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        $productos->darDeBaja($id);

        // 204 No Content: salió bien y la respuesta no tiene cuerpo (igual que el logout de Backend).
        http_response_code(204);
    }

    // POST /api/inventario/productos/{id}/reactivar
    // Deshace la baja: vuelve a la tienda (si no tiene todo vencido) con su stock e historial.
    // Si ya estaba activo no cambia nada.
    public static function reactivar(array $parametros): void
    {
        self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);

        if ($id === null || !$productos->existe($id)) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        $productos->reactivar($id);

        Response::json($productos->buscarPorId($id));
    }

    // POST /api/inventario/productos/{id}/stock — {cantidad, lote_id} (un lote que ya existe),
    // {cantidad, lote: {codigo, vencimiento}} (un lote nuevo, el número que sigue) o {cantidad} (el
    // lote sin vencimiento, solo si el producto no vence).
    public static function cargarStock(array $parametros): void
    {
        $sesion = self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);
        $actual = $id === null ? null : $productos->vence($id);

        if ($actual === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        $datos = Request::json();
        $errores = [];
        $cantidad = ValidadorProducto::cantidad($datos['cantidad'] ?? null);
        if ($cantidad === null) {
            $errores['cantidad'] = 'La cantidad debe ser un número entero entre 1 y 100.000';
        }

        $loteId = null;
        $nuevo = null;
        if (($datos['lote_id'] ?? null) !== null) {
            $loteId = self::id(['id' => is_int($datos['lote_id']) ? (string) $datos['lote_id'] : $datos['lote_id']]);
            if ($loteId === null) {
                $errores['lote_id'] = 'Elegí un lote del producto';
            }
        } elseif (($datos['lote'] ?? null) !== null) {
            [$nuevo, $erroresLote] = Lotes::validarNuevo($datos['lote'], date('Y-m-d'), $actual['vence']);
            foreach ($erroresLote as $campo => $mensaje) {
                $errores["lote.$campo"] = $mensaje;
            }
        }

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $resultado = $productos->cargarStock($id, $cantidad, (int) $sesion['id'], $loteId, $nuevo);

        if ($resultado === 'baja') {
            Response::json(['error' => 'El producto está dado de baja'], 409);
            return;
        }
        if (is_string($resultado)) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => self::errorDeLote($resultado, 'producto')]], 400);
            return;
        }

        Response::json(['id' => $id, 'stock' => $resultado['stock'], 'lote' => $resultado['lote']]);
    }

    // PUT /api/inventario/productos/{id}/lotes/{lote} — {codigo, vencimiento}: corrige un lote mal
    // cargado (el número L1, L2… no cambia).
    public static function editarLote(array $parametros): void
    {
        self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);
        $loteId = self::id(['id' => $parametros['lote'] ?? '']);
        $actual = $id === null ? null : $productos->vence($id);

        if ($actual === null || $loteId === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        // Se puede corregir a una fecha pasada (un lote que ya venció y se cargó con otra fecha). Si el
        // producto vence, la fecha es obligatoria; si no vence, no lleva (029).
        [$limpios, $errores] = Lotes::validarNuevo(Request::json(), '0000-00-00', $actual['vence']);
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        if (!$productos->editarLote($id, $loteId, $limpios)) {
            Response::json(['error' => 'Ese lote no es de este producto'], 404);
            return;
        }

        Response::json($productos->buscarPorId($id));
    }

    // POST /api/inventario/productos/{id}/retiro
    // Retiro manual de stock (como en insumos): resta unidades y registra una SALIDA con motivo AJUSTE.
    public static function retirarStock(array $parametros): void
    {
        $sesion = self::veterinario(true);

        $productos = self::repositorio();
        $id = self::id($parametros);

        if ($id === null || !$productos->existe($id)) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        $datos = Request::json();
        $cantidad = ValidadorProducto::cantidad($datos['cantidad'] ?? null);

        if ($cantidad === null) {
            Response::json([
                'error' => 'Datos inválidos',
                'campos' => ['cantidad' => 'La cantidad debe ser un número entero entre 1 y 100.000']
            ], 400);
            return;
        }

        // De qué lote (028). Sin lote: de los que vencen primero (los vencidos antes que nada).
        $loteId = null;
        if (($datos['lote_id'] ?? null) !== null) {
            $loteId = self::id(['id' => is_int($datos['lote_id']) ? (string) $datos['lote_id'] : $datos['lote_id']]);
            if ($loteId === null) {
                Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => 'Elegí un lote del producto']], 400);
                return;
            }
        }

        $stock = $productos->retirarStock($id, $cantidad, (int) $sesion['id'], $loteId);

        if ($stock === 'baja') {
            Response::json(['error' => 'El producto está dado de baja'], 409);
            return;
        }
        if (is_string($stock)) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => self::errorDeLote($stock, 'producto')]], 400);
            return;
        }
        if (is_array($stock)) {
            Response::json(['error' => 'Stock insuficiente', 'stock' => $stock[1]], 409);
            return;
        }

        Response::json(['id' => $id, 'stock' => $stock]);
    }

    // GET /api/inventario/productos/{id}/movimientos
    public static function movimientos(array $parametros): void
    {
        self::veterinario();

        $productos = self::repositorio();
        $id = self::id($parametros);

        if ($id === null || !$productos->existe($id)) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
            return;
        }

        Response::json(['datos' => $productos->movimientos($id)]);
    }

    // GET /api/inventario/productos/movimientos?producto=&desde=&hasta=
    // Los movimientos de todos los productos (o de uno), con el filtro de fechas: la pantalla
    // "Productos → Movimientos" de la veterinaria.
    public static function todosLosMovimientos(): void
    {
        self::veterinario();

        $query = Request::query();
        $producto = self::id(['id' => $query['producto'] ?? '']);
        [$desde, $hasta, $errores] = ReferenciaMovimiento::fechas($query['desde'] ?? null, $query['hasta'] ?? null);
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        Response::json(['datos' => self::repositorio()->todosLosMovimientos($producto, $desde, $hasta)]);
    }

    // GET /api/inventario/salud
    // Igual que la salud de Backend: "funciona" quiere decir que también llega a MySQL.
    public static function salud(): void
    {
        try {
            Database::conectar()->query('SELECT 1');
        } catch (Throwable $error) {
            Logger::error('Salud de Inventario: no se pudo conectar a la base: ' . $error->getMessage());
            Response::json(['estado' => 'sin conexión a la base', 'modulo' => 'Inventario'], 503);
            return;
        }

        Response::json(['estado' => 'funciona', 'modulo' => 'Inventario']);
    }

    // ---------- Ayudas ----------

    // El mensaje de "vence" cuando se quiere cambiar con stock (lo usa también InsumoControlador:
    // un insumo no se vende, se usa).
    public static function cambioDeVence(int $stock, string $que = 'producto'): string
    {
        $salida = $que === 'insumo' ? 'usá' : 'vendé';

        return "Se puede cambiar solo sin stock: retirá o $salida las $stock unidades primero";
    }

    // El mensaje de cada motivo por el que no se carga o retira de un lote (Lotes::paraCargar).
    // $que: "producto" o "insumo" (lo usa también InsumoControlador).
    public static function errorDeLote(string $motivo, string $que): string
    {
        return match ($motivo) {
            'lote_vencido' => 'Ese lote está vencido: cargá en otro o creá uno nuevo',
            'lote_no_corresponde' => "Ese lote no sigue la regla del $que (si vence, cada lote lleva su vencimiento): cargá en otro o creá uno nuevo",
            'falta_lote' => "Este $que vence: elegí un lote o creá uno nuevo con su vencimiento",
            default => "Ese lote no es de este $que",
        };
    }

    // Exige sesión de veterinario (401 o 403 si no) y, para POST, PUT y DELETE, el token CSRF (403).
    // Auth (de comun/) ya responde y corta si algo falla. Devuelve los datos del JWT (id, rol, csrf).
    private static function veterinario(bool $conCsrf = false): array
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);

        if ($conCsrf) {
            Auth::verificarCsrf($sesion);
        }

        return $sesion;
    }

    // El {id} de la ruta como entero positivo, o null si no lo es (y entonces se responde 404).
    private static function id(array $parametros): ?int
    {
        $id = $parametros['id'] ?? '';

        if (!is_string($id) || !ctype_digit($id) || strlen($id) > 10) {
            return null;
        }

        $id = (int) $id;

        return $id > 0 && $id <= 4294967295 ? $id : null; // INT UNSIGNED
    }

    private static function repositorio(): ProductoRepositorio
    {
        return new ProductoRepositorio(Database::conectar());
    }
}
