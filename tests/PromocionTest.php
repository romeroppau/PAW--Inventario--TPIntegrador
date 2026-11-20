<?php

use PHPUnit\Framework\TestCase;

// Pruebas de las reglas de una promoción (src/Promocion.php): el porcentaje y hasta cuándo dura.
// No usan la base de datos. "Hoy" es el viernes 20/11/2026.
class PromocionTest extends TestCase
{
    private function hoy(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-20 18:30:00', new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    public function testUnaPromocionValidaDevuelveLosDatosLimpios(): void
    {
        [$limpios, $errores] = Promocion::validar(['porcentaje' => 30, 'hasta' => '2026-12-10'], $this->hoy(), '2026-12-10');

        $this->assertSame([], $errores);
        $this->assertSame(['porcentaje' => 30, 'hasta' => '2026-12-10'], $limpios);
    }

    public function testElPorcentajeVaDeCincoANoventaYEsEntero(): void
    {
        foreach ([4, 91, 0, 12.5, '', null, 'treinta', true] as $porcentaje) {
            [, $errores] = Promocion::validar(['porcentaje' => $porcentaje, 'hasta' => '2026-11-30'], $this->hoy(), null);
            $this->assertArrayHasKey('porcentaje', $errores, var_export($porcentaje, true));
        }

        // Los extremos valen, y un número que llega como texto también.
        foreach ([5, 90, '15'] as $porcentaje) {
            [, $errores] = Promocion::validar(['porcentaje' => $porcentaje, 'hasta' => '2026-11-30'], $this->hoy(), null);
            $this->assertSame([], $errores, var_export($porcentaje, true));
        }
    }

    public function testPuedeTerminarHoyPeroNoAntes(): void
    {
        [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => '2026-11-20'], $this->hoy(), null);
        $this->assertSame([], $errores);

        [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => '2026-11-19'], $this->hoy(), null);
        $this->assertSame('La promoción no puede terminar antes de hoy', $errores['hasta']);
    }

    public function testDuraComoMuchoNoventaDias(): void
    {
        [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => '2027-02-18'], $this->hoy(), null);
        $this->assertSame([], $errores);

        [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => '2027-02-19'], $this->hoy(), null);
        $this->assertSame('La promoción puede durar como mucho 90 días (hasta el 18/02/2027)', $errores['hasta']);
    }

    public function testNoPuedeDurarMasQueElProducto(): void
    {
        [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => '2026-12-11'], $this->hoy(), '2026-12-10');

        $this->assertSame('El producto vence el 10/12/2026: la promoción no puede durar más que eso', $errores['hasta']);
    }

    public function testLaFechaTieneQueExistir(): void
    {
        foreach (['2026-02-30', '30/11/2026', '', null, 20261130] as $hasta) {
            [, $errores] = Promocion::validar(['porcentaje' => 20, 'hasta' => $hasta], $this->hoy(), null);
            $this->assertSame('Elegí hasta qué día dura la promoción', $errores['hasta'] ?? null, var_export($hasta, true));
        }
    }

    public function testSinDatosMarcaLosDosCampos(): void
    {
        [$limpios, $errores] = Promocion::validar('no es un objeto', $this->hoy(), null);

        $this->assertSame([], $limpios);
        $this->assertSame(['porcentaje', 'hasta'], array_keys($errores));
    }
}
