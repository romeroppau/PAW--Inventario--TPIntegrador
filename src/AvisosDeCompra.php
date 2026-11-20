<?php

// Los avisos que salen en el momento de comprar (contrato: docs/api/contrato-tienda.md, "Avisos"):
//
//   Momento                         A quién                  Canales
//   Confirma ("Ir a pagar")         comprador                campana
//   Paga (o se aplica un pago       comprador                campana y WhatsApp (constancia del pago)
//   tardío, que ya tiene su aviso)  cada veterinaria         campana ("nuevo pedido para retirar")
//
// Las veterinarias son todos los usuarios con rol VETERINARIO (no hay ids fijos). Cada aviso tiene
// un id_operacion fijo por compra y por destinatario: un reintento nunca lo duplica.
// Si Backend no responde, la compra sigue igual: el error queda en el log (Avisos::mandar).
class AvisosDeCompra
{
    public function __construct(private CompraRepositorio $compras)
    {
    }

    // La compra quedó reservada esperando el pago.
    public function reservada(string $numero): void
    {
        $compra = $this->compras->comprador($numero);
        if ($compra === null) {
            return;
        }

        Avisos::mandar(
            (int) $compra['cliente_id'],
            Compra::mensajeReservada($compra['nombre'], $numero, new DateTimeImmutable($compra['fecha'])),
            "compra-$numero-reservada",
            new DateTimeImmutable(),
            Avisos::SOLO_CAMPANA
        );
    }

    // La compra quedó paga. Con $avisarComprador = false (pago tardío, que ya le avisó el suyo) solo
    // se avisa a las veterinarias.
    public function pagada(string $numero, bool $avisarComprador = true): void
    {
        $compra = $this->compras->comprador($numero);
        if ($compra === null) {
            return;
        }
        $fecha = new DateTimeImmutable($compra['fecha']);
        $ahora = new DateTimeImmutable();

        if ($avisarComprador) {
            Avisos::mandar((int) $compra['cliente_id'], Compra::mensajePagada($compra['nombre'], $numero, $fecha), "compra-$numero-pagada", $ahora);
        }

        $mensaje = Compra::mensajeNuevoPedido($compra['nombre'], $numero, (float) $compra['total'], $fecha);
        foreach ($this->compras->veterinarias() as $veterinariaId) {
            Avisos::mandar($veterinariaId, $mensaje, "compra-$numero-pagada-vet-$veterinariaId", $ahora, Avisos::SOLO_CAMPANA);
        }
    }
}
