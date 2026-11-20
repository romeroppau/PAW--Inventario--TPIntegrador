<?php

// Los vencimientos de las compras de la tienda (contrato: docs/api/contrato-tienda.md,
// "POST /api/tienda/tareas/vencer" y "Avisos al comprador"):
// - cancela las compras sin pagar que pasaron su tiempo para pagar (a cualquier hora);
// - cancela las pagadas que pasaron su plazo de retiro (desde la hora de los avisos del día siguiente),
//   sin devolución; en los dos casos el stock vuelve;
// - manda el primer y el último recordatorio, y el aviso de cancelación (desde la hora de los avisos);
// - reintenta el aviso de los pedidos pagados que canceló el comprador (sale enseguida al cancelar).
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
            $retirarHasta = new DateTimeImmutable($compra['retirar_hasta']);
            $recordatorio = Compra::recordatorioDeHoy(new DateTimeImmutable($compra['fecha']), $retirarHasta, $ahora);
            if ($recordatorio === null) {
                continue;
            }

            $mensaje = $recordatorio === 'dia2'
                ? Compra::mensajeDia2($compra['nombre'], $compra['numero'], $retirarHasta)
                : Compra::mensajeDia4($compra['nombre'], $compra['numero'], $retirarHasta);

            if (Avisos::mandar((int) $compra['cliente_id'], $mensaje, "compra-{$compra['numero']}-$recordatorio", $ahora)) {
                $nuevos++;
            }
        }

        foreach ($this->compras->canceladasPagadasConComprador($ahora) as $compra) {
            if ($this->avisarCancelacion($compra, $ahora)) {
                $nuevos++;
            }
        }

        return $nuevos;
    }

    // El aviso de que el comprador canceló un pedido pagado: sale enseguida, desde la cancelación.
    // Si Backend falla, lo reintenta la tarea programada ese mismo día.
    public function avisarCancelacionPorComprador(string $numero, DateTimeImmutable $ahora): void
    {
        foreach ($this->compras->canceladasPagadasConComprador($ahora, $numero) as $compra) {
            $this->avisarCancelacion($compra, $ahora);
        }
    }

    // El aviso de una compra pagada que se canceló, si corresponde hoy. Devuelve true si es nuevo.
    // - Venció el plazo de retiro: el día de la cancelación, desde la hora de los avisos.
    // - La canceló el comprador: el mismo día que la canceló, a cualquier hora.
    private function avisarCancelacion(array $compra, DateTimeImmutable $ahora): bool
    {
        $retirarHasta = new DateTimeImmutable($compra['retirar_hasta']);

        if ($compra['motivo_cancelacion'] === 'VENCIDA') {
            if (!Compra::avisoDeCancelacionHoy($retirarHasta, $ahora)) {
                return false;
            }
            $mensaje = Compra::mensajeCancelada($compra['nombre'], $compra['numero'], $retirarHasta);
            $id = "compra-{$compra['numero']}-cancelada";
        } else {
            if (substr((string) $compra['cancelada_en'], 0, 10) !== $ahora->format('Y-m-d')) {
                return false;
            }
            $mensaje = Compra::mensajeCanceladaPorComprador($compra['nombre'], $compra['numero']);
            $id = "compra-{$compra['numero']}-cancelada-por-cliente";
        }

        return (bool) Avisos::mandar((int) $compra['cliente_id'], $mensaje, $id, $ahora);
    }
}
