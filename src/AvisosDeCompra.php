<?php

// Los avisos que salen en el momento de comprar (contrato: docs/api/contrato-tienda.md, "Avisos"):
//
//   Momento                         A quién                  Canales
//   Paga (también un pago tardío    comprador                campana y WhatsApp (constancia del pago)
//   que se pudo usar)               cada veterinaria         campana ("nuevo pedido para retirar")
//
// Al confirmar ("Ir a pagar") no se avisa: en ese momento el comprador ya está yendo a Mercado Pago
// y vería el aviso recién a la vuelta, cuando ya no sirve.
//
// Las veterinarias son todos los usuarios con rol VETERINARIO (no hay ids fijos). Cada aviso tiene
// un id_operacion fijo por compra y por destinatario: un reintento nunca lo duplica.
// Si Backend no responde, la compra sigue igual: el error queda en el log (Avisos::mandar).
class AvisosDeCompra
{
    public function __construct(private CompraRepositorio $compras)
    {
    }

    // La compra quedó paga: la constancia al comprador y el aviso a cada veterinaria.
    public function pagada(string $numero): void
    {
        $compra = $this->compras->comprador($numero);
        if ($compra === null) {
            return;
        }
        $fecha = new DateTimeImmutable($compra['fecha']);
        $ahora = new DateTimeImmutable();

        Avisos::mandar((int) $compra['cliente_id'], Compra::mensajePagada($compra['nombre'], $numero, $fecha), "compra-$numero-pagada", $ahora);

        $mensaje = Compra::mensajeNuevoPedido($compra['nombre'], $numero, (float) $compra['total'], $fecha);
        foreach ($this->compras->veterinarias() as $veterinariaId) {
            Avisos::mandar($veterinariaId, $mensaje, "compra-$numero-pagada-vet-$veterinariaId", $ahora, Avisos::SOLO_CAMPANA);
        }
    }
}
