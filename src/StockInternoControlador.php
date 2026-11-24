<?php

class StockInternoControlador
{
    private const MAX_ITEMS = 50;
    private const MAX_ID_OPERACION = 64; // verificá el largo real de la columna en la migración 002

    public static function descontar(): void
    {
        // 1. Token de servicio (antes de leer el cuerpo)
        $tokenConfigurado = Config::get('INTERNAL_TOKEN');
        $tokenRecibido = $_SERVER['HTTP_X_SERVICIO_TOKEN'] ?? '';

        if (
            $tokenConfigurado === null || $tokenConfigurado === '' ||
            $tokenRecibido === '' ||
            !hash_equals($tokenConfigurado, $tokenRecibido)
        ) {
            Response::error('No autorizado', 401);
            return;
        }

        // 2. Validar el cuerpo
        $datos = Request::json();
        $items = self::validar($datos);

        if ($items === null) {
            Response::error('Datos de solicitud inválidos', 400);
            return;
        }

        // 3. Descontar todo el lote en una sola transacción
        try {
            $repositorio = new StockInternoRepositorio(Database::conectar());
            $resultado = $repositorio->descontar($datos['usuario_id'], $items);

            switch ($resultado['resultado']) {
                case 'APLICADO':
                    Response::json(['aplicado' => true], 200);
                    return;
                case 'DUPLICADA':
                    Response::json(['aplicado' => false], 200);
                    return;
                case 'STOCK_INSUFICIENTE':
                    Response::json([
                        'error' => 'Stock insuficiente',
                        'items' => $resultado['items'],
                    ], 409);
                    return;
                case 'DADO_DE_BAJA':
                    Response::json([
                        'error' => 'Ítem dado de baja',
                        'motivo' => 'DADO_DE_BAJA',
                        'items' => $resultado['items'],
                    ], 409);
                    return;
                case 'OPERACION_DISTINTA':
                    Response::json([
                        'error' => 'La operación ya se aplicó con otros datos',
                        'motivo' => 'OPERACION_DISTINTA',
                        'items' => $resultado['items'],
                    ], 409);
                    return;
                case 'ITEM_INEXISTENTE':
                    Response::json([
                        'error' => 'Ítem no encontrado',
                        'items' => $resultado['items'],
                    ], 404);
                    return;
                default:
                    Response::error('No se pudo procesar el descuento de stock', 500);
            }
        } catch (Throwable $e) {
            Logger::error('Error al descontar stock interno: ' . get_class($e));
            Response::error('No se pudo procesar el descuento de stock', 500);
        }
    }

    /** Devuelve la lista de ítems si el cuerpo es válido, o null. */
    private static function validar(mixed $datos): ?array
    {
        if (!is_array($datos)) {
            return null;
        }

        $usuarioId = $datos['usuario_id'] ?? null;
        $items = $datos['items'] ?? null;

        if (!is_int($usuarioId) || $usuarioId <= 0) return null;
        if (!is_array($items) || !array_is_list($items)) return null;
        if ($items === [] || count($items) > self::MAX_ITEMS) return null;

        $operaciones = [];

        foreach ($items as $item) {
            if (!is_array($item)) return null;

            $tipo = $item['tipo'] ?? null;
            $id = $item['id'] ?? null;
            $cantidad = $item['cantidad'] ?? null;
            $idOperacion = $item['id_operacion'] ?? null;

            if (!in_array($tipo, ['PRODUCTO', 'INSUMO'], true)) return null;
            if (!is_int($id) || $id <= 0) return null;
            if (!is_int($cantidad) || $cantidad <= 0) return null;
            if (!is_string($idOperacion)) return null;

            $idOperacion = trim($idOperacion);
            if ($idOperacion === '' || strlen($idOperacion) > self::MAX_ID_OPERACION) return null;

            // Repetido dentro del mismo pedido: se rechaza acá, para que no se
            // confunda con un "ya aplicado" cuando salte el índice único
            if (isset($operaciones[$idOperacion])) return null;
            $operaciones[$idOperacion] = true;
        }

        // Solo se pasan al repositorio los campos conocidos
        return array_map(fn($i) => [
            'tipo' => $i['tipo'],
            'id' => $i['id'],
            'cantidad' => $i['cantidad'],
            'id_operacion' => trim($i['id_operacion']),
        ], $items);
    }
}