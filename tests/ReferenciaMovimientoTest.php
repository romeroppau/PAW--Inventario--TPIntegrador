<?php

use PHPUnit\Framework\TestCase;

// De dónde salió un movimiento (src/ReferenciaMovimiento.php): la consulta o la compra, a partir del
// id_operacion; y el filtro de fechas de los movimientos. No usan la base de datos.
class ReferenciaMovimientoTest extends TestCase
{
    public function testUnMovimientoDeUnaConsulta(): void
    {
        $this->assertSame(['consulta_id' => 57, 'compra' => null], ReferenciaMovimiento::de('consulta-57-insumo-3'));
        $this->assertSame(['consulta_id' => 120, 'compra' => null], ReferenciaMovimiento::de('consulta-120-producto-9'));
    }

    public function testUnMovimientoDeUnaCompra(): void
    {
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('venta-V-000123-101'));
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('cancela-V-000123-101'));
        // Una compra que se canceló dos veces (CompraRepositorio::idUnico agrega -2, -3...).
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('cancela-V-000123-101-2'));
        // Los de las semillas, con prefijo.
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-030002'], ReferenciaMovimiento::de('prod-venta-V-030002-3001'));
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000101'], ReferenciaMovimiento::de('semilla-venta-V-000101-101'));
    }

    public function testLosDemasNoTienenReferencia(): void
    {
        foreach ([null, '', 'volumen-carga-12', 'retiro-abc', 'consulta-x-insumo-3', 'venta-V-12-1'] as $id) {
            $this->assertSame(['consulta_id' => null, 'compra' => null], ReferenciaMovimiento::de($id), var_export($id, true));
        }
    }

    public function testElFiltroDeFechas(): void
    {
        $this->assertSame(['2026-11-01', '2026-11-30', []], ReferenciaMovimiento::fechas('2026-11-01', '2026-11-30'));
        $this->assertSame([null, null, []], ReferenciaMovimiento::fechas(null, ''));

        [, , $errores] = ReferenciaMovimiento::fechas('2026-02-30', '30/11/2026');
        $this->assertSame(['desde', 'hasta'], array_keys($errores));

        [, , $errores] = ReferenciaMovimiento::fechas('2026-12-01', '2026-11-01');
        $this->assertSame('La fecha "desde" no puede ser posterior a "hasta"', $errores['desde']);
    }
}
