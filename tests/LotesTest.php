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
            'codigo' => 'El número de lote del fabricante puede tener hasta 40 caracteres',
            'vencimiento' => 'La fecha de vencimiento no puede ser anterior a hoy',
        ], $errores);

        [, $errores] = Lotes::validarNuevo(['vencimiento' => '2026-02-30'], '2026-01-01');
        $this->assertSame(['vencimiento' => 'La fecha de vencimiento no es válida'], $errores);
    }

    // "vence" (029): si vence, el lote lleva fecha; si no, no la lleva. Con null no se controla.
    public function testElLoteNuevoSigueLaReglaDeVence(): void
    {
        [, $errores] = Lotes::validarNuevo(['vencimiento' => null], '2026-11-26', true);
        $this->assertSame(['vencimiento' => 'Este producto vence: poné la fecha de vencimiento del lote'], $errores);
        [, $errores] = Lotes::validarNuevo(['vencimiento' => '2027-01-01'], '2026-11-26', false, 'insumo');
        $this->assertSame(['vencimiento' => 'Este insumo no vence: dejá el vencimiento vacío'], $errores);
        $this->assertSame([['codigo' => null, 'vencimiento' => null], []], Lotes::validarNuevo([], '2026-11-26', false));
        $this->assertSame([['codigo' => null, 'vencimiento' => '2027-01-01'], []],
            Lotes::validarNuevo(['vencimiento' => '2027-01-01'], '2026-11-26', true));
    }

    public function testVenceEsObligatorioEnElAltaYSiVieneEsUnBooleano(): void
    {
        $this->assertSame([null, 'Indicá si vence o no'], Lotes::validarVence([], true));
        $this->assertSame([null, null], Lotes::validarVence([], false), 'en la edición, si no viene no se cambia');
        $this->assertSame([false, null], Lotes::validarVence(['vence' => false], true));
        $this->assertSame([null, 'Indicá si vence o no'], Lotes::validarVence(['vence' => 'si'], false));
    }

    // L1, L2, L3… y, si tiene, el número del fabricante.
    public function testComoSeNombraUnLote(): void
    {
        $this->assertSame('L3', Lotes::nombre(3, null));
        $this->assertSame('L3', Lotes::nombre(3, ''));
        $this->assertSame('L1 (FL-2612)', Lotes::nombre(1, 'FL-2612'));
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
