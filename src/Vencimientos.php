<?php

// Los vencimientos de las compras de la tienda (contrato: docs/api/contrato-tienda.md,
// "POST /api/tienda/tareas/vencer" y "Avisos al comprador"):
// - cancela las compras sin pagar con más de 15 minutos (a cualquier hora);
// - cancela las pagadas que pasaron el plazo de retiro (desde las 9:00 del día siguiente al último),
//   sin devolución; en los dos casos el stock vuelve;
// - manda los recordatorios del día 2 y del día 4, y el aviso de cancelación (desde las 9:00).
//
// La tarea programada (GitHub Actions, cada 15 minutos) hace todo. Además, cada pedido a la tienda
// cancela "al pasar" las vencidas (sin avisos): así nunca se muestra stock reservado de más aunque
// la tarea se atrase. Los avisos de esas cancelaciones los manda la próxima corrida de la tarea.
class Vencimientos
{
    public function __construct(private CompraRepositorio $compras)
    {
    }

    // Antes de atender un pedido de la tienda. Si algo falla, queda en el log y el pedido sigue:
    // la tarea lo vuelve a intentar.
    public static function alPasar(): void
    {
        try {
            (new self(new CompraRepositorio(Database::conectar())))->cancelarVencidas(new DateTimeImmutable());
        } catch (Throwable $error) {
            Logger::error('Inventario: no se pudieron cancelar las compras vencidas al pasar: ' . $error->getMessage());
        }
    }

    // Cancela las vencidas. Devuelve cuántas canceló de cada tipo.
    public function cancelarVencidas(DateTimeImmutable $ahora): array
    {
        $canceladas = ['sin_pago' => 0, 'sin_retirar' => 0];

        foreach ($this->compras->vencidas($ahora) as $numero) {
            // Cada una en su propia transacción: si otra corrida la canceló primero, devuelve null.
            $motivo = $this->compras->cancelarVencida($numero, $ahora);
            if ($motivo !== null) {
                $canceladas[$motivo]++;
            }
        }

        return $canceladas;
    }

    // Manda los avisos que tocan hoy. Devuelve cuántos avisos nuevos salieron.
    // Si Backend falla, ese aviso se reintenta en la próxima corrida del mismo día (el id_operacion
    // fijo evita que salga dos veces).
    public function mandarAvisos(DateTimeImmutable $ahora): int
    {
        $nuevos = 0;

        foreach ($this->compras->pagadasConComprador() as $compra) {
            $fecha = new DateTimeImmutable($compra['fecha']);
            $recordatorio = Compra::recordatorioDeHoy($fecha, $ahora);
            if ($recordatorio === null) {
                continue;
            }

            $mensaje = $recordatorio === 'dia2'
                ? Compra::mensajeDia2($compra['nombre'], $compra['numero'], $fecha)
                : Compra::mensajeDia4($compra['nombre'], $compra['numero'], $fecha);

            if (Avisos::mandar((int) $compra['cliente_id'], $mensaje, "compra-{$compra['numero']}-$recordatorio", $ahora)) {
                $nuevos++;
            }
        }

        foreach ($this->compras->canceladasPagadasConComprador($ahora) as $compra) {
            $fecha = new DateTimeImmutable($compra['fecha']);
            if (!Compra::avisoDeCancelacionHoy($fecha, $ahora)) {
                continue;
            }

            $mensaje = Compra::mensajeCancelada($compra['nombre'], $compra['numero'], $fecha);
            if (Avisos::mandar((int) $compra['cliente_id'], $mensaje, "compra-{$compra['numero']}-cancelada", $ahora)) {
                $nuevos++;
            }
        }

        return $nuevos;
    }
}
