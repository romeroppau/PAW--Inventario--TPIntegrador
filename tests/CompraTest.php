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

    // Los plazos de una compra NUEVA salen de la configuración (en estas pruebas no está cargada, así
    // que se usan los valores por defecto: 15 minutos, 5 días hábiles, 09:00 y primer recordatorio el 2).
    public function testUnaCompraNuevaTieneQuinceMinutosParaPagarYCincoDiasHabilesParaRetirar(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');

        $this->assertEquals($this->fecha('2026-11-18 10:45:00'), Compra::pagarAntesDe($confirmada));
        // Miércoles 18 -> jueves 19 (1), viernes 20 (2), lunes 23 (3), martes 24 (4), miércoles 25 (5).
        $this->assertEquals($this->fecha('2026-11-25'), Compra::retirarHasta($confirmada));
    }

    public function testElSabadoYElDomingoNoCuentan(): void
    {
        // Viernes 20 -> lunes 23 (1), martes 24 (2), miércoles 25 (3), jueves 26 (4), viernes 27 (5).
        $this->assertEquals($this->fecha('2026-11-27'), Compra::retirarHasta($this->fecha('2026-11-20 18:00:00')));
        // Confirmada un sábado: el día hábil 1 es el lunes.
        $this->assertEquals($this->fecha('2026-11-23'), Compra::diaHabil($this->fecha('2026-11-21 12:00:00'), 1));
        // El día hábil anterior a un lunes es el viernes.
        $this->assertEquals($this->fecha('2026-11-20'), Compra::diaHabilAnterior($this->fecha('2026-11-23')));
        $this->assertSame(5, Compra::diasHabilesEntre($this->fecha('2026-11-20 18:00:00'), $this->fecha('2026-11-27')));
    }

    // De acá en adelante, las reglas usan los plazos GUARDADOS de cada compra (no la configuración de hoy).

    public function testElLinkDeMercadoPagoVenceUnMinutoAntesQueLaCompra(): void
    {
        $this->assertEquals($this->fecha('2026-11-18 10:44:00'), Compra::linkDePagoVence($this->fecha('2026-11-18 10:45:00')));
    }

    public function testSinPagarVenceCuandoPasaSuTiempoParaPagar(): void
    {
        $pagarAntesDe = $this->fecha('2026-11-18 10:45:00');

        $this->assertFalse(Compra::vencioSinPagar($pagarAntesDe, $this->fecha('2026-11-18 10:44:59')));
        $this->assertTrue(Compra::vencioSinPagar($pagarAntesDe, $this->fecha('2026-11-18 10:45:00')));
    }

    public function testSeCancelaPorNoRetirarDesdeLasNueveDelDiaSiguienteAlUltimo(): void
    {
        $retirarHasta = $this->fecha('2026-11-25');   // último día: miércoles 25

        $this->assertFalse(Compra::vencioSinRetirar($retirarHasta, $this->fecha('2026-11-25 23:59:00')));
        $this->assertFalse(Compra::vencioSinRetirar($retirarHasta, $this->fecha('2026-11-26 08:59:00')));
        $this->assertTrue(Compra::vencioSinRetirar($retirarHasta, $this->fecha('2026-11-26 09:00:00')));
    }

    public function testElAvisoDeCancelacionSaleSoloElDiaQueSeCancela(): void
    {
        $retirarHasta = $this->fecha('2026-11-25');   // se cancela el jueves 26 a las 9:00

        $this->assertFalse(Compra::avisoDeCancelacionHoy($retirarHasta, $this->fecha('2026-11-26 08:59:00')));
        $this->assertTrue(Compra::avisoDeCancelacionHoy($retirarHasta, $this->fecha('2026-11-26 09:00:00')));
        $this->assertTrue(Compra::avisoDeCancelacionHoy($retirarHasta, $this->fecha('2026-11-26 23:45:00')));
        $this->assertFalse(Compra::avisoDeCancelacionHoy($retirarHasta, $this->fecha('2026-11-27 09:00:00')));
    }

    public function testElPrimerRecordatorioElDiaHabilDosYElUltimoElDiaHabilAnteriorAlUltimo(): void
    {
        $confirmada = $this->fecha('2026-11-18 10:30:00');
        $retirarHasta = $this->fecha('2026-11-25');

        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-19 10:00:00')));   // día 1
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-20 08:30:00')));   // día 2, antes de las 9
        $this->assertSame('dia2', Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-20 09:00:00')));
        $this->assertSame('dia2', Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-20 23:45:00')));
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-23 15:00:00')));   // día 3: ninguno
        $this->assertSame('dia4', Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-24 09:15:00')));   // el anterior al último
        $this->assertNull(Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-25 10:00:00')));   // el último día
    }

    public function testSiElUltimoDiaEsUnLunesElUltimoRecordatorioSaleElViernes(): void
    {
        // Comprada el lunes 16: el último día es el lunes 23; el último recordatorio, el viernes 20.
        $confirmada = $this->fecha('2026-11-16 12:00:00');
        $retirarHasta = $this->fecha('2026-11-23');

        $this->assertSame('dia4', Compra::recordatorioDeHoy($confirmada, $retirarHasta, $this->fecha('2026-11-20 10:00:00')));
        $this->assertStringContainsString('el lunes 23/11 es el último día', Compra::mensajeDia4('Ana Pérez', 'V-000003', $retirarHasta));
    }

    public function testLosMensajesUsanElPrimerNombreYLaFechaLimite(): void
    {
        $retirarHasta = $this->fecha('2026-11-25');

        $dia2 = Compra::mensajeDia2('Ana Pérez', 'V-000003', $retirarHasta);
        $this->assertStringContainsString('¡Hola, Ana!', $dia2);
        $this->assertStringContainsString('hasta el miércoles 25/11 inclusive', $dia2);
        $this->assertStringContainsString('Política de cancelación', $dia2);

        $this->assertStringContainsString('el miércoles 25/11 es el último día', Compra::mensajeDia4('Ana Pérez', 'V-000003', $retirarHasta));
        $this->assertStringContainsString('venció el plazo de retiro (25/11)', Compra::mensajeCancelada('Ana Pérez', 'V-000003', $retirarHasta));

        // Los mensajes van en un solo renglón (WhatsApp no acepta saltos de línea).
        $this->assertStringNotContainsString("\n", $dia2);
    }

    public function testLosAvisosAlComprar(): void
    {
        $retirarHasta = $this->fecha('2026-11-25');

        $pagada = Compra::mensajePagada('Ana Pérez', 'V-000105', $retirarHasta);
        $this->assertStringContainsString('Recibimos el pago de tu pedido V-000105', $pagada);
        $this->assertStringContainsString('hasta el miércoles 25/11 inclusive', $pagada);

        $vet = Compra::mensajeNuevoPedido('Ana Pérez', 'V-000105', 31999.8, $retirarHasta);
        $this->assertSame('🛍️ Nuevo pedido para retirar: V-000105 de Ana Pérez ($ 31.999,80). Fecha límite de retiro: miércoles 25/11.', $vet);

        $this->assertStringContainsString('¡Hola, Ana!', $pagada);
        $this->assertStringNotContainsString("\n", $pagada . $vet);
    }

    public function testElAvisoDelPagoDevueltoDiceElMotivoEnPalabrasSimples(): void
    {
        $vencido = Compra::mensajePagoDevuelto('Ana Pérez', 'V-000010', 'CANCELADA');
        $this->assertSame('Hola, Ana. Tu pago del pedido V-000010 llegó cuando ya había terminado el tiempo para pagar y, mientras tanto, otra persona compró los productos que habías elegido y no quedó stock, así que te devolvimos el dinero. Puede tardar unos días en verse en tu medio de pago. 🐾', $vencido);

        $yaPagado = Compra::mensajePagoDevuelto('Ana Pérez', 'V-000010', 'PAGADA');
        $this->assertStringContainsString('Tu pedido V-000010 ya estaba pagado, así que te devolvimos el pago que hiciste de más.', $yaPagado);

        $otro = Compra::mensajePagoDevuelto('Ana Pérez', 'V-000010', 'PENDIENTE_PAGO');
        $this->assertStringContainsString('te devolvimos el dinero', $otro);
        $this->assertStringNotContainsString("\n", $vencido . $yaPagado . $otro);
    }

    // ---------- Reintegro al cancelar un pedido pagado ----------

    public function testConAnticipacionEsHasta48HorasAntesDelFinalDelUltimoDia(): void
    {
        // Último día el miércoles 25: termina el miércoles a las 24:00; 48 horas antes, el lunes 23 a las 24:00.
        $retirarHasta = $this->fecha('2026-11-25');

        $this->assertEquals($this->fecha('2026-11-23 23:59:59'), Compra::reintegroMayorHasta($retirarHasta, 48));
        // Con 24 horas: hasta el martes 24 a las 23:59:59.
        $this->assertEquals($this->fecha('2026-11-24 23:59:59'), Compra::reintegroMayorHasta($retirarHasta, 24));
    }

    public function testCancelarAntesDevuelveMasQueCancelarAUltimoMomento(): void
    {
        $retirarHasta = $this->fecha('2026-11-25');

        $this->assertSame(75, Compra::porcentajeDeReintegro($retirarHasta, $this->fecha('2026-11-20 10:00:00'), 75, 50, 48));
        $this->assertSame(75, Compra::porcentajeDeReintegro($retirarHasta, $this->fecha('2026-11-23 23:59:59'), 75, 50, 48));
        $this->assertSame(50, Compra::porcentajeDeReintegro($retirarHasta, $this->fecha('2026-11-24 00:00:00'), 75, 50, 48));
        $this->assertSame(50, Compra::porcentajeDeReintegro($retirarHasta, $this->fecha('2026-11-25 18:00:00'), 75, 50, 48));
        // Con 24 horas, el martes todavía es "con anticipación".
        $this->assertSame(75, Compra::porcentajeDeReintegro($retirarHasta, $this->fecha('2026-11-24 12:00:00'), 75, 50, 24));
    }

    public function testElMontoEsElPorcentajeDelTotalConCentavos(): void
    {
        $this->assertSame(23999.85, Compra::montoDeReintegro(31999.8, 75));
        $this->assertSame(15999.9, Compra::montoDeReintegro(31999.8, 50));
        $this->assertSame(0.0, Compra::montoDeReintegro(31999.8, 0));
    }

    public function testSinConfiguracionLaPoliticaEs75Y50Con48Horas(): void
    {
        $this->assertSame(['anticipado' => 75, 'ultimo_momento' => 50, 'horas' => 48], Compra::politicaDeReintegro());
    }

    public function testElAvisoDeQueElCompradorCanceloDiceCuantoSeLeDevuelve(): void
    {
        $mensaje = Compra::mensajeCanceladaPorComprador('Ana Pérez', 'V-000010', 23999.85, 75);

        $this->assertStringStartsWith('Hola, Ana. Cancelaste tu pedido V-000010', $mensaje);
        $this->assertStringContainsString('Te devolvemos $ 23.999,85 (el 75 % de lo que pagaste) a tu medio de pago', $mensaje);
        $this->assertStringNotContainsString("\n", $mensaje);

        // Si la veterinaria configuró 0 %: no se reintegra.
        $this->assertStringContainsString('el importe no se reintegra', Compra::mensajeCanceladaPorComprador('Ana Pérez', 'V-000010', 0, 0));
    }
}
