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

        $insumos = self::repositorio()->listarTodos();

        Response::json(['datos' => array_map(fn (Insumo $insumo) => $insumo->aJson(), $insumos)]);
    }

    // GET /api/inventario/insumos/{id}
    public static function detalle(array $parametros): void
    {
        self::veterinario();

        $insumo = self::buscar(self::repositorio(), $parametros);
        if ($insumo === null) {
            return;
        }

        Response::json($insumo->aJson());
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

        Response::json($insumos->buscarPorId($id)->aJson(), 201);
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

        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $insumos->actualizar($insumo->id, $limpios);

        Response::json($insumos->buscarPorId($insumo->id)->aJson());
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

    // POST /api/inventario/insumos/{id}/stock
    public static function cargarStock(array $parametros): void
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

        $actualizado = $insumos->cargarStock($insumo->id, $cantidad, (int) $sesion['id']);

        // El insumo existe (se comprobó arriba), así que si no se pudo cargar es porque está dado de baja.
        if ($actualizado === null) {
            Response::json(['error' => self::DADO_DE_BAJA], 409);
            return;
        }

        Response::json(self::stock($actualizado));
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

        $actualizado = $insumos->retirarStock($insumo->id, $cantidad, (int) $sesion['id']);

        // Si no se pudo, se vuelve a leer para saber por qué: dado de baja o stock insuficiente.
        if ($actualizado === null) {
            $insumo = $insumos->buscarPorId($insumo->id);

            if (!$insumo->activo) {
                Response::json(['error' => self::DADO_DE_BAJA], 409);
            } else {
                Response::json(['error' => 'Stock insuficiente', 'stock' => $insumo->stock], 409);
            }
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

    // ---------- Ayudas ----------

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
            'stock_bajo' => $insumo->stockBajo()
        ];
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
