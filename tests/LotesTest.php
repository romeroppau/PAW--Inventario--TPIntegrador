<?php

use PHPUnit\Framework\TestCase;

// Las reglas de los lotes y de la promoción por lote (migración 028) que no usan la base.
// Lo que sí la usa (salir del lote que vence primero, cargar en un lote, retirar) está en
// StockInternoRepositorioTest y ProductoRetiroTest.
class LotesTest extends TestCase
{
    public function testLaPromocionTieneDeTopeLasUnidadesDelLote(): void
    {
        $this->assertSame(3, Promocion::cantidadConDescuento(3, 10), 'entran todas');
        $this->assertSame(4, Promocion::cantidadConDescuento(6, 4), 'solo las 4 que le quedan al lote');
        $this->assertSame(0, Promocion::cantidadConDescuento(2, 0), 'lote sin unidades');
        $this->assertSame(0, Promocion::cantidadConDescuento(5, null), 'sin promoción');
    }

    public function testElSubtotalSeparaLasUnidadesConDescuento(): void
    {
        // 4 a 800 (20 % sobre 1000) y 2 a 1000.
        $this->assertSame(5200.0, Promocion::subtotal(6, 1000.0, 4, 800.0));
        $this->assertSame(2400.0, Promocion::subtotal(3, 1000.0, 3, 800.0));
        $this->assertSame(3000.0, Promocion::subtotal(3, 1000.0, 0, null));
        $this->assertSame(33.33, Promocion::subtotal(3, 11.11, 0, null));
    }

    public function testUnLoteNuevoValidaNumeroYVencimiento(): void
    {
        $this->assertSame([['codigo' => 'L-0425', 'vencimiento' => '2026-12-16'], []],
            Lotes::validarNuevo(['codigo' => '  L-0425 ', 'vencimiento' => '2026-12-16'], '2026-11-26'));
        $this->assertSame([['codigo' => null, 'vencimiento' => null], []],
            Lotes::validarNuevo(['codigo' => '', 'vencimiento' => ''], '2026-11-26'), 'sin número y sin vencimiento');

        [, $errores] = Lotes::validarNuevo(['codigo' => str_repeat('x', 41), 'vencimiento' => '2026-11-25'], '2026-11-26');
        $this->assertSame([
            'codigo' => 'El número de lote puede tener hasta 40 caracteres',
            'vencimiento' => 'La fecha de vencimiento no puede ser anterior a hoy',
        ], $errores);

        [, $errores] = Lotes::validarNuevo(['vencimiento' => '2026-02-30'], '2026-01-01');
        $this->assertSame(['vencimiento' => 'La fecha de vencimiento no es válida'], $errores);
    }

    public function testComoSeNombraUnLote(): void
    {
        $this->assertSame('lote L-0425 (vence el 16/12/2026)', Lotes::texto('L-0425', '2026-12-16'));
        $this->assertSame('lote que vence el 16/12/2026', Lotes::texto(null, '2026-12-16'));
        $this->assertSame('lote L-7 (no vence)', Lotes::texto('L-7', null));
        $this->assertSame('lote sin vencimiento', Lotes::texto(null, null));
    }

    // Los movimientos de un lote que no es el primero siguen enlazando a su consulta o compra.
    public function testLaReferenciaDeUnMovimientoConLote(): void
    {
        $this->assertSame(['consulta_id' => 57, 'compra' => null], ReferenciaMovimiento::de('consulta-57-producto-3-lote-12'));
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('venta-V-000123-7-lote-4'));
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('cancela-V-000123-7-lote-4-2'));
        $this->assertSame(['consulta_id' => null, 'compra' => 'V-000123'], ReferenciaMovimiento::de('revive-V-000123-7-lote-4'));
    }
}
