<?php

use PHPUnit\Framework\TestCase;

// Pruebas del validador de insumos: las reglas de "Validación de campos" del contrato
// (docs/api/contrato-insumos.md). No usan la base de datos.
class InsumoValidadorTest extends TestCase
{
    public function testUnAltaValidaNoTieneErrores(): void
    {
        [$limpios, $errores] = InsumoValidador::alta(['nombre' => '  Gasa estéril  ', 'costo' => 180.5, 'stock' => 100,
            'vence' => true, 'vencimiento' => '2099-01-31', 'lote' => ' GA-11 ']);

        $this->assertSame([], $errores);
        $this->assertSame('Gasa estéril', $limpios['nombre']);   // sin los espacios de los costados
        $this->assertSame(180.5, $limpios['costo']);
        $this->assertSame(100, $limpios['stock']);
        $this->assertSame([true, '2099-01-31', 'GA-11'], [$limpios['vence'], $limpios['vencimiento'], $limpios['lote']]);
    }

    // Migración 029: "vence" es obligatorio en el alta. Si vence y tiene stock inicial, el primer
    // lote lleva su vencimiento; si no vence, no lo lleva. En la edición, "vence" es opcional.
    public function testElAltaSigueLaReglaDeVence(): void
    {
        [, $errores] = InsumoValidador::alta(['nombre' => 'Gasa', 'costo' => 1, 'stock' => 5]);
        $this->assertSame(['vence' => 'Indicá si vence o no'], $errores);

        [, $errores] = InsumoValidador::alta(['nombre' => 'Gasa', 'costo' => 1, 'stock' => 5, 'vence' => true]);
        $this->assertSame(['vencimiento' => 'Este insumo vence: poné la fecha de vencimiento del lote'], $errores);

        [, $errores] = InsumoValidador::alta(['nombre' => 'Gasa', 'costo' => 1, 'stock' => 0, 'vence' => true]);
        $this->assertSame([], $errores, 'sin stock inicial todavía no hay lote');

        [, $errores] = InsumoValidador::alta(['nombre' => 'Guantes', 'costo' => 1, 'stock' => 5, 'vence' => false, 'vencimiento' => '2099-01-01']);
        $this->assertSame(['vencimiento' => 'Este insumo no vence: dejá el vencimiento vacío'], $errores);

        [$limpios, $errores] = InsumoValidador::edicion(['nombre' => 'Guantes', 'costo' => 1]);
        $this->assertSame([], $errores);
        $this->assertArrayNotHasKey('vence', $limpios);
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

    // Migración 025: la aplicación del calendario es opcional; vacía = no es una vacuna.
    public function testLaAplicacionDelCalendarioEsOpcionalYDeLaLista(): void
    {
        $base = ['nombre' => 'Vacuna antirrábica', 'costo' => 4300, 'stock' => 0];

        [$limpios] = InsumoValidador::alta($base + ['aplicacion' => 'ANTIRRABICA']);
        $this->assertSame('ANTIRRABICA', $limpios['aplicacion']);

        [$limpios] = InsumoValidador::alta($base + ['aplicacion' => '']);
        $this->assertNull($limpios['aplicacion']);

        [, $errores] = InsumoValidador::alta($base + ['aplicacion' => 'PELUQUERIA']);
        $this->assertSame('Elegí una vacuna o antiparasitario de la lista', $errores['aplicacion']);

        // En la edición, si no viene no se toca.
        [$limpios] = InsumoValidador::edicion(['nombre' => 'Gasa', 'costo' => 200]);
        $this->assertArrayNotHasKey('aplicacion', $limpios);
    }
}
