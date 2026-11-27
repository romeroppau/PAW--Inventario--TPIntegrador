<?php

// Las tareas programadas de Inventario, que llama GitHub Actions:
// - la de la tienda, cada 5 minutos (contrato: docs/api/contrato-tienda.md, "POST /api/tienda/tareas/vencer");
// - la baja de los lotes vencidos, una vez por día con el motor diario (docs/api/contrato.md,
//   "POST /api/inventario/tareas/vencer-lotes").
class TareaControlador
{
    // POST /api/tienda/tareas/vencer   (header X-Tarea-Token)
    public static function vencer(): void
    {
        if (!self::autorizada('/api/tienda/tareas/vencer')) {
            return;
        }

        $ahora = new DateTimeImmutable();
        $compras = new CompraRepositorio(Database::conectar());
        $vencimientos = new Vencimientos($compras);

        // Primero las cancelaciones: así el aviso de cancelación sale en la misma corrida.
        $canceladas = $vencimientos->cancelarVencidas($ahora);
        $avisos = $vencimientos->mandarAvisos($ahora);
        // Los reintegros que Mercado Pago no pudo hacer al cancelar.
        $reintegros = (new Reintegros($compras))->hacerPendientes();

        Response::json([
            'canceladas_sin_pago' => $canceladas['sin_pago'],
            'canceladas_vencidas' => $canceladas['sin_retirar'],
            'avisos' => $avisos,
            'reintegros' => $reintegros,
        ]);
    }

    // POST /api/inventario/tareas/vencer-lotes   (header X-Tarea-Token)
    public static function vencerLotes(): void
    {
        if (!self::autorizada('/api/inventario/tareas/vencer-lotes')) {
            return;
        }

        $resultado = (new BajaPorVencimiento(Database::conectar()))->ejecutar(new DateTimeImmutable());
        if ($resultado['unidades'] > 0) {
            Logger::info("Inventario: baja por vencimiento de {$resultado['lotes_productos']} lotes de productos y "
                . "{$resultado['lotes_insumos']} de insumos ({$resultado['unidades']} unidades)");
        }

        Response::json($resultado);
    }

    // El token de la tarea (TAREAS_TOKEN), comparado en tiempo constante. Sin él configurado, nadie
    // puede correr la tarea. Si no es válido, responde 401 y devuelve false.
    private static function autorizada(string $ruta): bool
    {
        $esperado = Config::get('TAREAS_TOKEN');
        $recibido = $_SERVER['HTTP_X_TAREA_TOKEN'] ?? '';

        if (!is_string($esperado) || $esperado === '' || $recibido === '' || !hash_equals($esperado, $recibido)) {
            Logger::info("Tarea rechazada: $ruta sin token válido");
            Response::error('No autorizado', 401);
            return false;
        }

        return true;
    }
}
