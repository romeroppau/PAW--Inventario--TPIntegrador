<?php

// Los endpoints de insumos: un método por endpoint (contrato en docs/api/contrato-insumos.md).
// Cada método lee el pedido, usa las otras clases y responde con Response::json (de comun/).
// Todos son solo para el veterinario. Los métodos son static para conectarlos al router así:
// [InsumoControlador::class, 'listado']. El router les pasa los parámetros de la ruta, por ejemplo ['id' => '7'].
class InsumoControlador
{
    private const NO_ENCONTRADO = 'Insumo no encontrado';
    private const DADO_DE_BAJA = 'El insumo está dado de baja';
    private const ERROR_CANTIDAD = 'La cantidad debe ser un número entero entre 1 y 100.000';

    // GET /api/inventario/insumos
    public static function listado(): void
    {
        self::veterinario();

        $repositorio = self::repositorio();
        $insumos = $repositorio->listarTodos();
        $lotes = $repositorio->lotes();

        Response::json([
            // Cada uno con sus lotes (029).
            'datos' => array_map(fn (Insumo $insumo) => $insumo->aJson() + ['lotes' => $lotes[$insumo->id] ?? []], $insumos),
            // Para el filtro "solo stock bajo" y el texto del listado (Entrega 5).
            'limite_stock_bajo' => Insumo::limiteStockBajo(),
        ]);
    }

    // GET /api/inventario/insumos/{id}
    public static function detalle(array $parametros): void
    {
        self::veterinario();

        $repositorio = self::repositorio();
        $insumo = self::buscar($repositorio, $parametros);
        if ($insumo === null) {
            return;
        }

        Response::json(self::conLotes($repositorio, $insumo));
    }

    // POST /api/inventario/insumos
    public static function crear(): void
    {
        $sesion = self::veterinario(true);

        [$limpios, $errores] = InsumoValidador::alta(Request::json());

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $insumos = self::repositorio();
        $id = $insumos->insertar($limpios, (int) $sesion['id']);

        Response::json(self::conLotes($insumos, $insumos->buscarPorId($id)), 201);
    }

    // PUT /api/inventario/insumos/{id}
    public static function editar(array $parametros): void
    {
        self::veterinario(true);

        $insumos = self::repositorio();

        // Primero se comprueba que exista: si no, 404 aunque los datos también sean inválidos.
        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        [$limpios, $errores] = InsumoValidador::edicion(Request::json());
        // Si vence o no (029) se cambia solo sin stock: si no, sus lotes no seguirían la regla.
        if (isset($limpios['vence']) && $limpios['vence'] !== $insumo->vence && $insumo->stock > 0) {
            $errores['vence'] = ProductoControlador::cambioDeVence($insumo->stock, 'insumo');
        }

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $insumos->actualizar($insumo->id, $limpios);

        Response::json(self::conLotes($insumos, $insumos->buscarPorId($insumo->id)));
    }

    // DELETE /api/inventario/insumos/{id}
    public static function darDeBaja(array $parametros): void
    {
        self::veterinario(true);

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        $insumos->darDeBaja($insumo->id);

        // 204 No Content: salió bien y la respuesta no tiene cuerpo.
        http_response_code(204);
    }

    // POST /api/inventario/insumos/{id}/reactivar
    // Deshace la baja. Si ya estaba activo no cambia nada (se puede repetir sin problema).
    public static function reactivar(array $parametros): void
    {
        self::veterinario(true);

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        $insumos->reactivar($insumo->id);

        Response::json($insumos->buscarPorId($insumo->id)->aJson());
    }

    // POST /api/inventario/insumos/{id}/pedido     marca "Ya pedido" al proveedor
    // DELETE /api/inventario/insumos/{id}/pedido   lo desmarca (por si se marcó por error)
    public static function marcarPedido(array $parametros): void
    {
        self::cambiarPedido($parametros, true);
    }

    public static function desmarcarPedido(array $parametros): void
    {
        self::cambiarPedido($parametros, false);
    }

    // POST /api/inventario/insumos/{id}/stock — {cantidad, lote_id} (un lote que ya existe),
    // {cantidad, lote: {codigo, vencimiento}} (un lote nuevo, el número que sigue) o {cantidad} (el
    // lote sin vencimiento, solo si el insumo no vence). Igual que en productos (029).
    public static function cargarStock(array $parametros): void
    {
        $sesion = self::veterinario(true);

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        $datos = Request::json();
        $errores = [];
        if (InsumoValidador::cantidad($datos['cantidad'] ?? null) === null) {
            $errores['cantidad'] = self::ERROR_CANTIDAD;
        }
        $loteId = null;
        $nuevo = null;
        if (($datos['lote_id'] ?? null) !== null) {
            $loteId = self::id(['id' => is_int($datos['lote_id']) ? (string) $datos['lote_id'] : $datos['lote_id']]);
            if ($loteId === null) {
                $errores['lote_id'] = 'Elegí un lote del insumo';
            }
        } elseif (($datos['lote'] ?? null) !== null) {
            [$nuevo, $erroresLote] = Lotes::validarNuevo($datos['lote'], date('Y-m-d'), $insumo->vence, 'insumo');
            foreach ($erroresLote as $campo => $mensaje) {
                $errores["lote.$campo"] = $mensaje;
            }
        }
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $resultado = $insumos->cargarStock($insumo->id, $datos['cantidad'], (int) $sesion['id'], $loteId, $nuevo);

        // El insumo existe (se comprobó arriba): 'baja' es que está dado de baja.
        if ($resultado === 'baja') {
            Response::json(['error' => self::DADO_DE_BAJA], 409);
            return;
        }
        if (is_string($resultado)) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => ProductoControlador::errorDeLote($resultado, 'insumo')]], 400);
            return;
        }

        Response::json(self::stock($resultado[0]) + ['lote' => $resultado[1]]);
    }

    // PUT /api/inventario/insumos/{id}/lotes/{lote} — {codigo, vencimiento}: corrige un lote mal
    // cargado (el número L1, L2… no cambia). Igual que en productos.
    public static function editarLote(array $parametros): void
    {
        self::veterinario(true);

        $insumos = self::repositorio();
        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }
        $loteId = self::id(['id' => $parametros['lote'] ?? '']);
        if ($loteId === null) {
            Response::json(['error' => 'Ese lote no es de este insumo'], 404);
            return;
        }

        // Se puede corregir a una fecha pasada (un lote que ya venció y se cargó con otra fecha).
        [$limpios, $errores] = Lotes::validarNuevo(Request::json(), '0000-00-00', $insumo->vence, 'insumo');
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        if (!$insumos->editarLote($insumo->id, $loteId, $limpios)) {
            Response::json(['error' => 'Ese lote no es de este insumo'], 404);
            return;
        }

        Response::json(self::conLotes($insumos, $insumos->buscarPorId($insumo->id)));
    }

    // POST /api/inventario/insumos/{id}/retiro
    public static function retirar(array $parametros): void
    {
        $sesion = self::veterinario(true);

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        $cantidad = self::cantidad();
        if ($cantidad === null) {
            return;
        }

        // De qué lote (029). Sin lote: de los que vencen primero (los vencidos antes que nada).
        $datos = Request::json();
        $loteId = null;
        if (($datos['lote_id'] ?? null) !== null) {
            $loteId = self::id(['id' => is_int($datos['lote_id']) ? (string) $datos['lote_id'] : $datos['lote_id']]);
            if ($loteId === null) {
                Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => 'Elegí un lote del insumo']], 400);
                return;
            }
        }

        $actualizado = $insumos->retirarStock($insumo->id, $cantidad, (int) $sesion['id'], $loteId);

        if ($actualizado === 'baja') {
            Response::json(['error' => self::DADO_DE_BAJA], 409);
            return;
        }
        if (is_string($actualizado)) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['lote_id' => 'Ese lote no es de este insumo']], 400);
            return;
        }
        if (is_array($actualizado)) {
            Response::json(['error' => 'Stock insuficiente', 'stock' => $actualizado[1]], 409);
            return;
        }

        Response::json(self::stock($actualizado));
    }

    // GET /api/inventario/insumos/{id}/movimientos
    public static function movimientos(array $parametros): void
    {
        self::veterinario();

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        Response::json(['datos' => $insumos->movimientos($insumo->id)]);
    }

    // GET /api/inventario/insumos/movimientos?insumo=
    // Movimientos de todos los insumos. El parámetro insumo es un filtro opcional:
    // si no es un id válido se ignora (igual que los filtros del catálogo).
    public static function todosLosMovimientos(): void
    {
        self::veterinario();

        $query = Request::query();
        $filtro = self::id(['id' => $query['insumo'] ?? '']);
        [$desde, $hasta, $errores] = ReferenciaMovimiento::fechas($query['desde'] ?? null, $query['hasta'] ?? null);
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        Response::json(['datos' => self::repositorio()->movimientos($filtro, $desde, $hasta)]);
    }

    // ---------- Ayudas ----------

    private static function cambiarPedido(array $parametros, bool $pedido): void
    {
        self::veterinario(true);

        $insumos = self::repositorio();

        $insumo = self::buscar($insumos, $parametros);
        if ($insumo === null) {
            return;
        }

        $actualizado = $insumos->marcarPedido($insumo->id, $pedido);

        if ($actualizado === null) {
            Response::json(['error' => self::DADO_DE_BAJA], 409);
            return;
        }

        // Solo se marca un insumo con stock bajo: con stock suficiente no hay nada que pedir.
        if ($pedido && !$actualizado->pedido) {
            Response::json(['error' => 'Solo se marca como pedido un insumo con stock bajo (' . Insumo::limiteStockBajo() . ' o menos)'], 409);
            return;
        }

        Response::json(self::stock($actualizado));
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

    // Busca el insumo del {id} de la ruta. Si el id no es válido o no existe, responde 404 y devuelve null.
    private static function buscar(InsumoRepositorio $insumos, array $parametros): ?Insumo
    {
        $id = self::id($parametros);
        $insumo = $id === null ? null : $insumos->buscarPorId($id);

        if ($insumo === null) {
            Response::json(['error' => self::NO_ENCONTRADO], 404);
        }

        return $insumo;
    }

    // La cantidad del pedido (carga o retiro). Si no es válida, responde 400 y devuelve null.
    private static function cantidad(): ?int
    {
        $cantidad = InsumoValidador::cantidad(Request::json()['cantidad'] ?? null);

        if ($cantidad === null) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['cantidad' => self::ERROR_CANTIDAD]], 400);
        }

        return $cantidad;
    }

    // Respuesta de la carga y el retiro: lo justo para actualizar la fila sin recargar la página.
    private static function stock(Insumo $insumo): array
    {
        return [
            'id' => $insumo->id,
            'stock' => $insumo->stock,
            'estado' => $insumo->estado(),
            'stock_bajo' => $insumo->stockBajo(),
            'stock_sin_vencer' => $insumo->sinVencer(),
            'vencimiento' => $insumo->vencimiento,
        ];
    }

    // El insumo del contrato con sus lotes (029).
    private static function conLotes(InsumoRepositorio $insumos, Insumo $insumo): array
    {
        return $insumo->aJson() + ['lotes' => $insumos->lotes($insumo->id)[$insumo->id] ?? []];
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

    private static function repositorio(): InsumoRepositorio
    {
        return new InsumoRepositorio(Database::conectar());
    }
}
