<?php

use PHPUnit\Framework\TestCase;

// Pruebas del validador de insumos: las reglas de "Validación de campos" del contrato
// (docs/api/contrato-insumos.md). No usan la base de datos.
class InsumoValidadorTest extends TestCase
{
    public function testUnAltaValidaNoTieneErrores(): void
    {
        [$limpios, $errores] = InsumoValidador::alta(['nombre' => '  Gasa estéril  ', 'costo' => 180.5, 'stock' => 100]);

        $this->assertSame([], $errores);
        $this->assertSame('Gasa estéril', $limpios['nombre']);   // sin los espacios de los costados
        $this->assertSame(180.5, $limpios['costo']);
        $this->assertSame(100, $limpios['stock']);
    }

    public function testSiFaltanLosCamposDiceQueSonObligatorios(): void
    {
        [, $errores] = InsumoValidador::alta([]);

        $this->assertSame('El nombre es obligatorio', $errores['nombre']);
        $this->assertSame('El costo es obligatorio', $errores['costo']);
        $this->assertSame('El stock es obligatorio', $errores['stock']);
    }

    public function testUnNombreDeUnaLetraEsInvalido(): void
    {
        [, $errores] = InsumoValidador::alta(['nombre' => 'A', 'costo' => 10, 'stock' => 1]);

        $this->assertSame('El nombre debe tener entre 2 y 120 caracteres', $errores['nombre']);
    }

    public function testElCostoPuedeSerCero(): void
    {
        [, $errores] = InsumoValidador::alta(['nombre' => 'Insumo donado', 'costo' => 0, 'stock' => 5]);

        $this->assertArrayNotHasKey('costo', $errores);
    }

    public function testUnCostoNegativoEsInvalido(): void
    {
        [, $errores] = InsumoValidador::alta(['nombre' => 'Jeringa', 'costo' => -1, 'stock' => 5]);

        $this->assertSame('El costo debe ser un número mayor o igual a 0 con hasta 2 decimales', $errores['costo']);
    }

    public function testUnCostoConTresDecimalesOComoTextoEsInvalido(): void
    {
        [, $conDecimales] = InsumoValidador::alta(['nombre' => 'Jeringa', 'costo' => 1.555, 'stock' => 5]);
        [, $comoTexto] = InsumoValidador::alta(['nombre' => 'Jeringa', 'costo' => '150', 'stock' => 5]);

        $this->assertArrayHasKey('costo', $conDecimales);
        $this->assertArrayHasKey('costo', $comoTexto);
    }

    public function testUnStockNegativoONoEnteroEsInvalido(): void
    {
        [, $negativo] = InsumoValidador::alta(['nombre' => 'Jeringa', 'costo' => 10, 'stock' => -3]);
        [, $conComa] = InsumoValidador::alta(['nombre' => 'Jeringa', 'costo' => 10, 'stock' => 2.5]);

        $this->assertSame('El stock debe ser un número entero entre 0 y 1.000.000', $negativo['stock']);
        $this->assertArrayHasKey('stock', $conComa);
    }

    public function testLaEdicionIgnoraElStockYLosCamposQueNoSonDelContrato(): void
    {
        [$limpios, $errores] = InsumoValidador::edicion([
            'nombre' => 'Gasa', 'costo' => 200, 'stock' => 999, 'estado' => 'DISPONIBLE', 'activo' => true
        ]);

        $this->assertSame([], $errores);
        $this->assertSame(['nombre', 'costo'], array_keys($limpios));   // solo lo que se puede editar
    }

    public function testLaCantidadDeCargaORetiroEsUnEnteroDe1A100000(): void
    {
        $this->assertSame(10, InsumoValidador::cantidad(10));
        $this->assertNull(InsumoValidador::cantidad(0));
        $this->assertNull(InsumoValidador::cantidad(100001));
        $this->assertNull(InsumoValidador::cantidad('10'));
        $this->assertNull(InsumoValidador::cantidad(null));
    }
}
