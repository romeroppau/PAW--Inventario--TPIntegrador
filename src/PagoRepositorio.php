<?php

// El SQL de los pagos de la tienda (tabla pago, y la factura, el carrito y el stock que mueven).
// Contrato: docs/api/contrato-tienda.md, "Pago". Usa las ayudas de CompraRepositorio (bloquear la
// factura, cancelar, los ítems y los movimientos de stock).
class PagoRepositorio extends CompraRepositorio
{
    // Lo necesario para crear el pago: la compra (si es de ese comprador) y sus productos, o null.
    public function paraPagar(string $numero, int $compradorId): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.total, c.cliente_id
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE f.numero = ?"
        );
        $consulta->execute([$numero]);
        $compra = $consulta->fetch();

        if ($compra === false || (int) $compra['cliente_id'] !== $compradorId) {
            return null;
        }

        $compra['items'] = $this->itemsDeFactura((int) $compra['id']);

        return $compra;
    }

    // Registra lo que dijo Mercado Pago de un pago (ya consultado a Mercado Pago, nunca el aviso tal cual).
    // $pago = ['id', 'estado', 'referencia' (el número de la compra), 'monto'].
    // Con $compradorId, solo si la compra es de ese comprador (la vuelta del comprador a la página).
    //
    // Devuelve qué pasó:
    //   'aprobado'   la compra esperaba el pago: queda PAGADA (lista para retirar)
    //   'revivido'   el pago llegó con la compra ya cancelada, pero había stock: se reserva de nuevo y queda PAGADA
    //   'devolver'   no se puede aplicar (cancelada sin stock, ya pagada con otro pago o monto distinto):
    //                se registra DEVUELTO y quien llama le pide la devolución a Mercado Pago
    //   'rechazado'  pago rechazado: se registra y la compra sigue esperando el pago
    //   'repetido'   ese pago ya estaba registrado (un aviso repetido): no se hace nada
    //   'ignorado'   todavía no está resuelto (pendiente): se espera el próximo aviso
    //   'no_existe'  no hay una compra con ese número (o es de otro comprador)
    public function registrar(array $pago, ?int $compradorId = null): string
    {
        if (!in_array($pago['estado'], ['approved', 'rejected', 'cancelled'], true)) {
            return 'ignorado';
        }

        $this->db->beginTransaction();
        try {
            $factura = $this->bloquearFactura($pago['referencia']);
            if ($factura === null || ($compradorId !== null && (int) $factura['cliente_id'] !== $compradorId)) {
                $this->db->rollBack();
                return 'no_existe';
            }

            $consulta = $this->db->prepare('SELECT 1 FROM pago WHERE id_externo = ?');
            $consulta->execute([$pago['id']]);
            if ($consulta->fetchColumn() !== false) {
                $this->db->rollBack();
                return 'repetido';
            }

            $resultado = $pago['estado'] === 'approved' ? $this->aplicar($factura, $pago) : 'rechazado';

            $estadoPago = ['aprobado' => 'APROBADO', 'revivido' => 'APROBADO', 'devolver' => 'DEVUELTO', 'rechazado' => 'RECHAZADO'][$resultado];
            $this->db->prepare('INSERT INTO pago (factura_compra_id, monto, id_externo, estado, fecha) VALUES (?, ?, ?, ?, NOW())')
                ->execute([$factura['id'], number_format($pago['monto'], 2, '.', ''), $pago['id'], $estadoPago]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $resultado;
    }

    // ---------- Ayudas ----------

    // Un pago aprobado: según cómo está la compra, la deja pagada, la revive o hay que devolverlo.
    private function aplicar(array $factura, array $pago): string
    {
        // Se cobró otro monto (no debería pasar): no se aplica.
        if (abs($pago['monto'] - (float) $factura['total']) > 0.009) {
            Logger::error("Inventario: el pago {$pago['id']} de {$factura['numero']} es por {$pago['monto']} y la compra por {$factura['total']}");
            return 'devolver';
        }

        if ($factura['estado'] === 'PENDIENTE_PAGO') {
            $this->marcarPagada($factura);
            return 'aprobado';
        }

        // Pago tardío: la compra ya se canceló (venció el tiempo para pagar). Si todavía hay stock de
        // todo, se vuelve a reservar y la compra sigue como si se hubiera pagado a tiempo.
        if ($factura['estado'] === 'CANCELADA' && $this->reservarDeNuevo($factura)) {
            $this->marcarPagada($factura);
            return 'revivido';
        }

        // Cancelada sin stock, o ya pagada (o entregada) con otro pago.
        return 'devolver';
    }

    private function marcarPagada(array $factura): void
    {
        $this->db->prepare("UPDATE factura_compra SET estado = 'PAGADA', motivo_cancelacion = NULL, cancelada_en = NULL WHERE id = ?")
            ->execute([$factura['id']]);
        $this->db->prepare("UPDATE carrito SET estado = 'PAGADO' WHERE id = ?")->execute([$factura['carrito_id']]);
    }

    // Vuelve a reservar el stock de una compra cancelada. Si algún producto no alcanza o ya no está a
    // la venta, no reserva nada y devuelve false.
    private function reservarDeNuevo(array $factura): bool
    {
        $consulta = $this->db->prepare(
            'SELECT i.producto_id, i.cantidad, p.stock, (' . self::A_LA_VENTA . ') AS a_la_venta
             FROM item_carrito i JOIN producto p ON p.id = i.producto_id
             WHERE i.carrito_id = ?
             ORDER BY i.producto_id
             FOR UPDATE'
        );
        $consulta->execute([$factura['carrito_id']]);
        $items = $consulta->fetchAll();

        foreach ($items as $item) {
            if (!$item['a_la_venta'] || (int) $item['cantidad'] > (int) $item['stock']) {
                return false;
            }
        }

        foreach ($items as $item) {
            $this->db->prepare('UPDATE producto SET stock = stock - ? WHERE id = ?')->execute([$item['cantidad'], $item['producto_id']]);
            $this->registrarMovimiento((int) $item['producto_id'], 'SALIDA', 'VENTA', (int) $item['cantidad'],
                (int) $factura['cliente_id'], $this->idUnico("revive-{$factura['numero']}-{$item['producto_id']}"));
        }

        return true;
    }
}
