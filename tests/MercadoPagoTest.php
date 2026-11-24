<?php

use PHPUnit\Framework\TestCase;

// Prueba de la firma de los avisos de Mercado Pago (src/MercadoPago.php, firmaValida).
// No se conecta a Mercado Pago: arma la firma como lo hace él (HMAC-SHA256 con la clave secreta).
class MercadoPagoTest extends TestCase
{
    private const SECRETO = 'clave-secreta-de-prueba';

    private function firmar(string $manifiesto, string $ts = '1704908010'): string
    {
        return "ts=$ts,v1=" . hash_hmac('sha256', $manifiesto, self::SECRETO);
    }

    public function testAceptaUnAvisoFirmadoConLaClaveSecreta(): void
    {
        $firma = $this->firmar('id:123456;request-id:abc-123;ts:1704908010;');

        $this->assertTrue(MercadoPago::firmaValida($firma, 'abc-123', '123456', self::SECRETO));
    }

    public function testRechazaUnAvisoConOtroPagoOtraClaveOSinFirma(): void
    {
        $firma = $this->firmar('id:123456;request-id:abc-123;ts:1704908010;');

        $this->assertFalse(MercadoPago::firmaValida($firma, 'abc-123', '999999', self::SECRETO));   // otro pago
        $this->assertFalse(MercadoPago::firmaValida($firma, 'abc-123', '123456', 'otra-clave'));
        $this->assertFalse(MercadoPago::firmaValida('', 'abc-123', '123456', self::SECRETO));
        $this->assertFalse(MercadoPago::firmaValida($firma, 'abc-123', '123456', ''));          // sin clave configurada
        $this->assertFalse(MercadoPago::firmaValida('ts=1704908010', 'abc-123', '123456', self::SECRETO));
    }

    public function testSinRequestIdEsaParteNoVaYUnIdConLetrasVaEnMinusculas(): void
    {
        $sinRequest = $this->firmar('id:123456;ts:1704908010;');
        $conLetras = $this->firmar('id:abc123;request-id:r1;ts:1704908010;');

        $this->assertTrue(MercadoPago::firmaValida($sinRequest, '', '123456', self::SECRETO));
        $this->assertTrue(MercadoPago::firmaValida($conLetras, 'r1', 'ABC123', self::SECRETO));
    }
}
