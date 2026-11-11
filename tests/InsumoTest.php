<?php

use PHPUnit\Framework\TestCase;

// Reglas de la clase Insumo (sin base de datos): estado, stock bajo y "Ya pedido".
class InsumoTest extends TestCase
{
    private function insumo(int $stock, bool $pedido = false): Insumo
    {
        return new Insumo(1, 'Gasas', $stock, 10.0, true, $pedido);
    }

    public function testElEstadoSaleDelStock(): void
    {
        $this->assertSame('EN_FALTA', $this->insumo(0)->estado());
        $this->assertSame('DISPONIBLE', $this->insumo(5)->estado());
    }

    public function testSeMarcaPedidoSoloConStockBajo(): void
    {
        $bajo = $this->insumo(10);
        $this->assertTrue($bajo->marcarPedido());
        $this->assertSame('YA_PEDIDO', $bajo->estado());

        $suficiente = $this->insumo(11);
        $this->assertFalse($suficiente->marcarPedido());
        $this->assertSame('DISPONIBLE', $suficiente->estado());
    }

    public function testYaPedidoSeConservaAlDescontarYSeBorraAlReponer(): void
    {
        $insumo = $this->insumo(3, pedido: true);

        $insumo->descontarStock(3);
        $this->assertSame('YA_PEDIDO', $insumo->estado(), 'sigue pedido aunque llegue a 0');

        $insumo->reponer(20);
        $this->assertSame('DISPONIBLE', $insumo->estado(), 'llegó el pedido');
        $this->assertFalse($insumo->pedido);
    }

    public function testDesdeFilaLeeElYaPedido(): void
    {
        $fila = ['id' => '4', 'nombre' => 'Jeringas', 'stock' => '2', 'costo' => '5.50', 'estado' => 'YA_PEDIDO', 'activo' => '1'];

        $insumo = Insumo::desdeFila($fila);

        $this->assertTrue($insumo->pedido);
        $this->assertSame('YA_PEDIDO', $insumo->aJson()['estado']);
    }
}
