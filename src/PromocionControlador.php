<?php

// Los endpoints de las promociones (contrato: docs/api/contrato-tienda.md, sección "Promociones").
// La veterinaria las publica desde las sugerencias de Reportes ("Publicitar") y las termina desde ahí;
// la tienda muestra las vigentes con el precio rebajado.
class PromocionControlador
{
    // GET /api/inventario/promociones (veterinaria): las vigentes.
    public static function listado(): void
    {
        Auth::requerirRol(['VETERINARIO']);

        Response::json(['datos' => self::repositorio()->vigentes()]);
    }

    // POST /api/inventario/promociones (veterinaria, con CSRF): {producto_id, porcentaje, hasta}.
    public static function crear(): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $datos = Request::json();
        $promociones = self::repositorio();

        $productoId = self::id($datos['producto_id'] ?? null);
        $producto = $productoId === null ? null : $promociones->producto($productoId);
        if ($producto === null) {
            Response::json(['error' => 'Producto no encontrado'], 404);
            return;
        }
        if (!$producto['a_la_venta']) {
            Response::json(['error' => 'El producto no está a la venta (está dado de baja o vencido)'], 409);
            return;
        }

        $hoy = new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
        [$limpios, $errores] = Promocion::validar($datos, $hoy, $producto['vencimiento']);
        if ($errores !== []) {
            Response::json(['error' => 'Datos inválidos', 'campos' => $errores], 400);
            return;
        }

        $resultado = $promociones->crear($productoId, $limpios['porcentaje'], $limpios['hasta'], (int) $sesion['id']);
        if (is_array($resultado)) {
            $vigente = $resultado[1];
            Response::json([
                'error' => "Este producto ya tiene una promoción del {$vigente['porcentaje']} % hasta el "
                    . Promocion::texto($vigente['hasta']) . '. Terminala para publicar otra.',
                'promocion_id' => $vigente['id'],
            ], 409);
            return;
        }

        Logger::info("Promoción $resultado publicada: producto $productoId, {$limpios['porcentaje']} % hasta {$limpios['hasta']} (usuario {$sesion['id']})");
        Response::json($promociones->buscar($resultado), 201);
    }

    // DELETE /api/inventario/promociones/{id} (veterinaria, con CSRF): la termina ahora.
    public static function terminar(array $parametros): void
    {
        $sesion = Auth::requerirRol(['VETERINARIO']);
        Auth::verificarCsrf($sesion);

        $id = self::id($parametros['id'] ?? null);
        if ($id === null || !self::repositorio()->terminar($id)) {
            Response::json(['error' => 'No hay una promoción vigente con ese número'], 404);
            return;
        }

        Logger::info("Promoción $id terminada (usuario {$sesion['id']})");
        http_response_code(204);
    }

    // GET /api/tienda/promociones (público): los productos en promoción que se pueden comprar.
    public static function tienda(): void
    {
        $productos = new ProductoRepositorio(Database::conectar());

        Response::json(['datos' => $productos->enPromocion()]);
    }

    // Un id entero positivo (número o texto de dígitos), o null.
    private static function id(mixed $valor): ?int
    {
        if (is_int($valor)) {
            $valor = (string) $valor;
        }
        if (!is_string($valor) || !ctype_digit($valor) || strlen($valor) > 10) {
            return null;
        }
        $id = (int) $valor;

        return $id > 0 && $id <= 4294967295 ? $id : null;
    }

    private static function repositorio(): PromocionRepositorio
    {
        return new PromocionRepositorio(Database::conectar());
    }
}
