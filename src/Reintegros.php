<?php

// Hace en Mercado Pago los reintegros de los pedidos pagados que canceló el comprador
// (contrato: docs/api/contrato-tienda.md, "Reintegro al cancelar").
//
// Al cancelar, la compra queda con su reintegro PENDIENTE y se intenta enseguida. Si Mercado Pago no
// responde, queda pendiente y la tarea programada lo reintenta cada 15 minutos hasta que sale: el
// comprador nunca se queda sin su reintegro y la veterinaria no tiene que hacer nada a mano.
//
// En local y en GitHub (Mercado Pago simulado), y con los pagos que no son de Mercado Pago (los de
// las semillas o del pago simulado, que no tienen un número de pago real), no hay a quién pedírselo:
// el reintegro se marca hecho directamente.
class Reintegros
{
    public function __construct(private CompraRepositorio $compras)
    {
    }

    // Hace los pendientes (o solo el de $numero). Devuelve cuántos se hicieron.
    public function hacerPendientes(?string $numero = null): int
    {
        $hechos = 0;

        foreach ($this->compras->reintegrosPendientes($numero) as $reintegro) {
            $pagoId = (string) ($reintegro['pago_id'] ?? '');
            $deMercadoPago = !MercadoPago::simulado() && ctype_digit($pagoId);

            if ($deMercadoPago && !MercadoPago::devolverParte($pagoId, (float) $reintegro['monto'])) {
                continue;   // queda pendiente: lo reintenta la tarea
            }
            if ($this->compras->marcarReintegroHecho($reintegro['numero'])) {
                $hechos++;
            }
        }

        return $hechos;
    }
}
