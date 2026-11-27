<?php

use PHPUnit\Framework\TestCase;

// La baja de los lotes vencidos (27/11) contra un MySQL real: cada lote vencido con stock, de productos
// y de insumos, queda en 0 con su movimiento VENCIMIENTO, el stock baja y una segunda corrida no hace
// nada. Lo que vence hoy no se toca. Cada prueba crea sus productos e insumos y los borra al final.
class BajaPorVencimientoTest extends TestCase
{
    private PDO $db;
    private array $productos = [];
    private array $insumos = [];

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3307';
        $nombre = getenv('TEST_DB_NAME') ?: 'huellavet_test';
        $usuario = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $this->db = new PDO(
                "mysql:host=$host;port=$port;dbname=$nombre;charset=utf8mb4",
                $usuario,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('No se pudo conectar con MySQL: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (['producto' => $this->productos, 'insumo' => $this->insumos] as $tabla => $ids) {
            foreach ($ids as $id) {
                $this->db->prepare("DELETE FROM movimiento_inventario WHERE {$tabla}_id = ?")->execute([$id]);
                $this->db->prepare("DELETE FROM lote_$tabla WHERE {$tabla}_id = ?")->execute([$id]);
                $this->db->prepare("DELETE FROM $tabla WHERE id = ?")->execute([$id]);
            }
        }
    }

    public function testDaDeBajaLosLotesVencidosDeProductosEInsumos(): void
    {
        $producto = $this->crear('producto');
        $vencido = $this->agregarLote('producto', $producto, 8, '2020-01-01');
        $bueno = $this->agregarLote('producto', $producto, 5, '2099-12-31');
        $insumo = $this->crear('insumo');
        $vencidoInsumo = $this->agregarLote('insumo', $insumo, 3, '2020-06-30');
        $this->agregarLote('insumo', $insumo, 2, null);

        $baja = new BajaPorVencimiento($this->db);
        $baja->darDeBajaVencidos();

        $this->assertSame(0, $this->stockDelLote('producto', $vencido));
        $this->assertSame(5, $this->stockDelLote('producto', $bueno));
        $this->assertSame(5, $this->stock('producto', $producto));
        $this->assertSame(0, $this->stockDelLote('insumo', $vencidoInsumo));
        $this->assertSame(2, $this->stock('insumo', $insumo));

        $movimiento = $this->movimiento(BajaPorVencimiento::idOperacion('producto', $vencido));
        $this->assertSame(['SALIDA', 'VENCIMIENTO', 8, $vencido], [$movimiento['tipo'], $movimiento['motivo'], (int) $movimiento['cantidad'], (int) $movimiento['lote_id']]);
        $movimiento = $this->movimiento(BajaPorVencimiento::idOperacion('insumo', $vencidoInsumo));
        $this->assertSame(['SALIDA', 'VENCIMIENTO', 3, $vencidoInsumo], [$movimiento['tipo'], $movimiento['motivo'], (int) $movimiento['cantidad'], (int) $movimiento['lote_insumo_id']]);

        // El aviso de cada baja (lo arma bajasRecientes con lo que quedó).
        $mensajes = array_map([BajaPorVencimiento::class, 'mensaje'], array_values(array_filter(
            $baja->bajasRecientes(),
            fn ($b) => in_array($b['id_operacion'], [BajaPorVencimiento::idOperacion('producto', $vencido), BajaPorVencimiento::idOperacion('insumo', $vencidoInsumo)], true)
        )));
        $this->assertSame([
            'Dimos de baja el lote L1 del producto Producto de prueba vencimiento: 8 unidades vencieron el 01/01/2020. Stock actual: 5. Lo ves en Movimientos.',
            'Dimos de baja el lote L1 del insumo Insumo de prueba vencimiento: 3 unidades vencieron el 30/06/2020. Stock actual: 2. Lo ves en Movimientos.',
        ], $mensajes);
    }

    public function testUnaSegundaCorridaNoHaceNada(): void
    {
        $producto = $this->crear('producto');
        $vencido = $this->agregarLote('producto', $producto, 4, '2020-01-01');
        $baja = new BajaPorVencimiento($this->db);

        $this->assertSame(4, $baja->darDeBaja('producto', $vencido));
        $this->assertSame(0, $baja->darDeBaja('producto', $vencido));
        $consulta = $this->db->prepare("SELECT COUNT(*) FROM movimiento_inventario WHERE producto_id = ? AND motivo = 'VENCIMIENTO'");
        $consulta->execute([$producto]);
        $this->assertSame(1, (int) $consulta->fetchColumn());
    }

    public function testLoQueVenceHoyTodaviaNoSeDaDeBaja(): void
    {
        $hoy = (new DateTimeImmutable('now', new DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d');
        $producto = $this->crear('producto');
        $lote = $this->agregarLote('producto', $producto, 6, $hoy);

        $this->assertSame(0, (new BajaPorVencimiento($this->db))->darDeBaja('producto', $lote));
        $this->assertSame(6, $this->stock('producto', $producto));
    }

    private function crear(string $de): int
    {
        if ($de === 'producto') {
            $this->db->exec("INSERT INTO producto (nombre, precio, stock, categoria, especie_destino, etapa_vida, vence)
                             VALUES ('Producto de prueba vencimiento', 1000, 0, 'MEDICAMENTO', 'TODAS', 'TODAS', 1)");
            return $this->productos[] = (int) $this->db->lastInsertId();
        }
        $this->db->exec("INSERT INTO insumo (nombre, stock, costo, estado, activo, vence)
                         VALUES ('Insumo de prueba vencimiento', 0, 100, 'DISPONIBLE', 1, 1)");
        return $this->insumos[] = (int) $this->db->lastInsertId();
    }

    private function agregarLote(string $de, int $id, int $stock, ?string $vencimiento): int
    {
        $lotes = new Lotes($this->db, $de);
        $lote = $lotes->crear($id, null, $vencimiento);
        $lotes->sumar($lote, $stock);
        $lotes->sincronizar($id);

        return $lote;
    }

    private function movimiento(string $operacion): array
    {
        $consulta = $this->db->prepare('SELECT tipo, motivo, cantidad, lote_id, lote_insumo_id FROM movimiento_inventario WHERE id_operacion = ?');
        $consulta->execute([$operacion]);
        return $consulta->fetch();
    }

    private function stock(string $tabla, int $id): int
    {
        $consulta = $this->db->prepare("SELECT stock FROM $tabla WHERE id = ?");
        $consulta->execute([$id]);
        return (int) $consulta->fetchColumn();
    }

    private function stockDelLote(string $de, int $loteId): int
    {
        $consulta = $this->db->prepare("SELECT stock FROM lote_$de WHERE id = ?");
        $consulta->execute([$loteId]);
        return (int) $consulta->fetchColumn();
    }
}
