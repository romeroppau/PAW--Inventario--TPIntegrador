<?php

// La tarea programada de la tienda: la llama GitHub Actions cada 15 minutos.
// Contrato: docs/api/contrato-tienda.md, "POST /api/tienda/tareas/vencer".
class TareaControlador
{
    // POST /api/tienda/tareas/vencer   (header X-Tarea-Token)
    public static function vencer(): void
    {
        // El token de la tarea (TAREAS_TOKEN), comparado en tiempo constante. Sin él configurado,
        // nadie puede correr la tarea.
        $esperado = Config::get('TAREAS_TOKEN');
        $recibido = $_SERVER['HTTP_X_TAREA_TOKEN'] ?? '';

        if (!is_string($esperado) || $esperado === '' || $recibido === '' || !hash_equals($esperado, $recibido)) {
            Logger::info('Tarea rechazada: /api/tienda/tareas/vencer sin token válido');
            Response::error('No autorizado', 401);
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
}
