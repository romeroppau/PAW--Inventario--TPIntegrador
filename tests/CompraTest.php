<?php

use PHPUnit\Framework\TestCase;

// Pruebas de las reglas de una compra (src/Compra.php): plazos, días hábiles, recordatorios y avisos.
// No usan la base de datos. Las fechas son de Argentina.
class CompraTest extends TestCase
{
    private function fecha(string $texto): DateTimeImmutable
    {
        return new DateTimeImmutable($texto, new DateTimeZone('America/Argentina/Buenos_Aires'));
    }

    public function testElNumeroDeFacturaEmpiezaConVYTieneSeisDigitos(): void
    {
        $this->assertSame('V-000003', Compra::numero(3));
        $this->assertSame('V-001234', Compra::numero(1234));
    }

    public function testHayQuinceMinutosParaPagar(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');

        $this->assertEquals($this->fecha('2026-11-18 10:45:00'), Compra::pagarAntesDe($confirmada));
        $this->assertFalse(Compra::vencioSinPagar($confirmada, $this->fecha('2026-11-18 10:44:59')));
        $this->assertTrue(Compra::vencioSinPagar($confirmada, $this->fecha('2026-11-18 10:45:00')));
    }

    public function testConfirmadaUnMiercolesSeRetiraHastaElMiercolesSiguiente(): void
    {
        // Miércoles 18 -> jueves 19 (1), viernes 20 (2), lunes 23 (3), martes 24 (4), miércoles 25 (5).
        $this->assertEquals($this->fecha('2026-11-25'), Compra::retirarHasta($this->fecha('2026-11-18 10:30:00')));
    }

    public function testElSabadoYElDomingoNoCuentan(): void
    {
        // Viernes 20 -> lunes 23 (1), martes 24 (2), miércoles 25 (3), jueves 26 (4), viernes 27 (5).
        $this->assertEquals($this->fecha('2026-11-27'), Compra::retirarHasta($this->fecha('2026-11-20 18:00:00')));
        // Confirmada un sábado: el día hábil 1 es el lunes.
        $this->assertEquals($this->fecha('2026-11-23'), Compra::diaHabil($this->fecha('2026-11-21 12:00:00'), 1));
    }

    public function testSeCancelaPorNoRetirarDesdeLasNueveDelDiaSiguienteAlUltimo(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');   // último día: miércoles 25

        $this->assertFalse(Compra::vencioSinRetirar($confirmada, $this->fecha('2026-11-25 23:59:00')));
        $this->assertFalse(Compra::vencioSinRetirar($confirmada, $this->fecha('2026-11-26 08:59:00')));
        $this->assertTrue(Compra::vencioSinRetirar($confirmada, $this->fecha('2026-11-26 09:00:00')));
    }

    public function testElAvisoDeCancelacionSaleSoloElDiaQueSeCancela(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');   // se cancela el jueves 26 a las 9:00

        $this->assertFalse(Compra::avisoDeCancelacionHoy($confirmada, $this->fecha('2026-11-26 08:59:00')));
        $this->assertTrue(Compra::avisoDeCancelacionHoy($confirmada, $this->fecha('2026-11-26 09:00:00')));
        $this->assertTrue(Compra::avisoDeCancelacionHoy($confirmada, $this->fecha('2026-11-26 23:45:00')));
        $this->assertFalse(Compra::avisoDeCancelacionHoy($confirmada, $this->fecha('2026-11-27 09:00:00')));
    }

    public function testLosRecordatoriosSalenLosDiasHabilesDosYCuatroDesdeLasNueve(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');

        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-19 10:00:00')));   // día 1
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-20 08:30:00')));   // día 2, antes de las 9
        $this->assertSame('dia2', Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-20 09:00:00')));
        $this->assertSame('dia2', Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-20 23:45:00')));
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-23 15:00:00')));   // día 3: ninguno
        $this->assertSame('dia4', Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-24 09:15:00')));
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $this->fecha('2026-11-25 10:00:00')));   // día 5
    }

    public function testLosMensajesUsanElPrimerNombreYLaFechaLimite(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');

        $dia2 = Compra::mensajeDia2('Ana Pérez', 'V-000003', $confirmada);
        $this->assertStringContainsString('¡Hola, Ana!', $dia2);
        $this->assertStringContainsString('hasta el miércoles 25/11 inclusive', $dia2);
        $this->assertStringContainsString('Política de cancelación', $dia2);

        $this->assertStringContainsString('mañana (25/11)', Compra::mensajeDia4('Ana Pérez', 'V-000003', $confirmada));
        $this->assertStringContainsString('venció el plazo de retiro (25/11)', Compra::mensajeCancelada('Ana Pérez', 'V-000003', $confirmada));

        // Los mensajes van en un solo renglón (WhatsApp no acepta saltos de línea).
        $this->assertStringNotContainsString("\n", $dia2);
    }
}
