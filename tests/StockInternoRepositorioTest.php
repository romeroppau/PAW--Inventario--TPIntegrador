<?php

use PHPUnit\Framework\TestCase;

// Pruebas del descuento interno de stock (POST /internal/stock/descontar) contra un MySQL real:
// la idempotencia, el todo o nada y los errores dependen de la base (índice único, clave foránea).
// Cada prueba crea sus propios productos e insumos y los borra al final.
class StockInternoRepositorioTest extends TestCase
{
    private PDO $db;
    private StockInternoRepositorio $repo;
    private int $usuarioId;
    private array $productos = [];
    private array $insumos = [];
    private ?int $usuarioCreado = null;

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

        // Los movimientos tienen clave foránea a usuario. En CI la base está recién migrada y vacía:
        // se crea un usuario para la prueba (antes, sin usuarios, estas pruebas se salteaban).
        $id = $this->db->query('SELECT id FROM usuario ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            $this->db->exec("INSERT INTO usuario (nombre, email, password_hash, rol)
                             VALUES ('Prueba stock', 'stock-interno@prueba.test', 'x', 'VETERINARIO')");
            $id = $this->usuarioCreado = (int) $this->db->lastInsertId();
        }
        $this->usuarioId = (int) $id;

        $this->repo = new StockInternoRepositorio($this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (['producto' => $this->productos, 'insumo' => $this->insumos] as $tabla => $ids) {
            foreach ($ids as $id) {
                $this->db->prepare("DELETE FROM movimiento_inventario WHERE {$tabla}_id = ?")->execute([$id]);
                $this->db->prepare("DELETE FROM $tabla WHERE id = ?")->execute([$id]);
            }
        }
        if ($this->usuarioCreado !== null) {
            $this->db->prepare('DELETE FROM usuario WHERE id = ?')->execute([$this->usuarioCreado]);
        }
    }

    public function testDescuentaStockYRegistraElMovimiento(): void
    {
        $producto = $this->crearProducto(10);
        $operacion = $this->operacion();

        $r = $this->repo->descontar($this->usuarioId, [$this->item('PRODUCTO', $producto, 3, $operacion)]);

        $this->assertSame('APLICADO', $r['resultado']);
        $this->assertSame(7, $this->stock('producto', $producto));
        $mov = $this->movimiento($operacion);
        $this->assertSame(['SALIDA', 'CONSULTA', 3], [$mov['tipo'], $mov['motivo'], (int) $mov['cantidad']]);
    }

    public function testElMovimientoQuedaConLaHoraDeArgentina(): void
    {
        $producto = $this->crearProducto(10);
        $operacion = $this->operacion();

        $this->repo->descontar($this->usuarioId, [$this->item('PRODUCTO', $producto, 1, $operacion)]);

        // MySQL corre en UTC; la fecha guardada tiene que ser la de acá (UTC menos 3 horas).
        $fecha = $this->movimiento($operacion)['fecha'];
        $argentina = new DateTimeZone('-03:00');
        $diferencia = abs((new DateTimeImmutable('now', $argentina))->getTimestamp()
            - (new DateTimeImmutable($fecha, $argentina))->getTimestamp());
        $this->assertLessThan(120, $diferencia, "Fecha guardada: $fecha");
    }

    public function testNoDescuentaDosVecesLaMismaOperacion(): void
    {
        $producto = $this->crearProducto(10);
        $item = $this->item('PRODUCTO', $producto, 2, $this->operacion());

        $this->assertSame('APLICADO', $this->repo->descontar($this->usuarioId, [$item])['resultado']);
        $this->assertSame('DUPLICADA', $this->repo->descontar($this->usuarioId, [$item])['resultado']);
        $this->assertSame(8, $this->stock('producto', $producto));
    }

    public function testReintentoConItemNuevoDescuentaSoloElNuevo(): void
    {
        $a = $this->crearProducto(10);
        $b = $this->crearProducto(10);
        $itemA = $this->item('PRODUCTO', $a, 2, $this->operacion());
        $itemB = $this->item('PRODUCTO', $b, 3, $this->operacion());

        $this->repo->descontar($this->usuarioId, [$itemA]);
        $r = $this->repo->descontar($this->usuarioId, [$itemA, $itemB]);

        $this->assertSame('APLICADO', $r['resultado']);
        $this->assertSame(8, $this->stock('producto', $a), 'A no se descuenta dos veces');
        $this->assertSame(7, $this->stock('producto', $b), 'B sí se descuenta');
    }

    public function testReintentoConLaCantidadAumentadaSeRechaza(): void
    {
        $insumo = $this->crearInsumo(20);
        $otro = $this->crearProducto(10);
        $operacion = $this->operacion();

        $this->repo->descontar($this->usuarioId, [$this->item('INSUMO', $insumo, 2, $operacion)]);
        // Entre un intento y otro la cantidad pasó de 2 a 5: no se puede dar por aplicado (faltarían 3)
        // ni descontar 5 otra vez (serían 7). Se rechaza todo el pedido, también el ítem nuevo.
        $r = $this->repo->descontar($this->usuarioId, [
            $this->item('INSUMO', $insumo, 5, $operacion),
            $this->item('PRODUCTO', $otro, 1, $this->operacion()),
        ]);

        $this->assertSame('OPERACION_DISTINTA', $r['resultado']);
        $this->assertSame([['tipo' => 'INSUMO', 'id' => $insumo, 'cantidad_aplicada' => 2]], $r['items']);
        $this->assertSame(18, $this->stock('insumo', $insumo));
        $this->assertSame(10, $this->stock('producto', $otro));
    }

    public function testLaMismaOperacionParaOtroItemSeRechaza(): void
    {
        $a = $this->crearProducto(10);
        $b = $this->crearProducto(10);
        $operacion = $this->operacion();

        $this->repo->descontar($this->usuarioId, [$this->item('PRODUCTO', $a, 1, $operacion)]);
        $r = $this->repo->descontar($this->usuarioId, [$this->item('PRODUCTO', $b, 1, $operacion)]);

        $this->assertSame('OPERACION_DISTINTA', $r['resultado']);
        $this->assertSame(0, $r['items'][0]['cantidad_aplicada']);
        $this->assertSame(10, $this->stock('producto', $b));
    }

    public function testItemInexistenteSeRechazaSinDescontarNada(): void
    {
        $producto = $this->crearProducto(10);

        // Antes el movimiento chocaba con la clave foránea y terminaba en un 500.
        $r = $this->repo->descontar($this->usuarioId, [
            $this->item('PRODUCTO', $producto, 1, $this->operacion()),
            $this->item('INSUMO', 4000000000, 1, $this->operacion()),
        ]);

        $this->assertSame('ITEM_INEXISTENTE', $r['resultado']);
        $this->assertSame([['tipo' => 'INSUMO', 'id' => 4000000000]], $r['items']);
        $this->assertSame(10, $this->stock('producto', $producto));
    }

    public function testItemNuevoSinStockNoDescuentaNada(): void
    {
        $a = $this->crearProducto(10);
        $b = $this->crearProducto(1);

        $r = $this->repo->descontar($this->usuarioId, [
            $this->item('PRODUCTO', $a, 2, $this->operacion()),
            $this->item('PRODUCTO', $b, 5, $this->operacion()),
        ]);

        $this->assertSame('STOCK_INSUFICIENTE', $r['resultado']);
        $this->assertSame(10, $this->stock('producto', $a));
        $this->assertSame(1, $this->stock('producto', $b));
    }

    public function testItemDadoDeBajaNoSeDescuenta(): void
    {
        $insumo = $this->crearInsumo(10, activo: false);

        $r = $this->repo->descontar($this->usuarioId, [$this->item('INSUMO', $insumo, 1, $this->operacion())]);

        $this->assertSame('DADO_DE_BAJA', $r['resultado']);
        $this->assertSame(10, $this->stock('insumo', $insumo));
    }

    public function testInsumoEnCeroQuedaEnFaltaYElYaPedidoSeConserva(): void
    {
        $enFalta = $this->crearInsumo(2);
        $pedido = $this->crearInsumo(3, estado: 'YA_PEDIDO');

        $this->repo->descontar($this->usuarioId, [
            $this->item('INSUMO', $enFalta, 2, $this->operacion()),
            $this->item('INSUMO', $pedido, 3, $this->operacion()),
        ]);

        $this->assertSame('EN_FALTA', $this->estado($enFalta));
        $this->assertSame('YA_PEDIDO', $this->estado($pedido));
    }

    // ---------- Ayudas ----------

    private function item(string $tipo, int $id, int $cantidad, string $operacion): array
    {
        return ['tipo' => $tipo, 'id' => $id, 'cantidad' => $cantidad, 'id_operacion' => $operacion];
    }

    private function operacion(): string
    {
        return 'prueba-' . bin2hex(random_bytes(8));
    }

    private function crearProducto(int $stock): int
    {
        $this->db->prepare("INSERT INTO producto (nombre, precio, stock, categoria, especie_destino, etapa_vida)
                            VALUES ('Producto de prueba stock interno', 1000, ?, 'MEDICAMENTO', 'TODAS', 'TODAS')")
            ->execute([$stock]);
        return $this->productos[] = (int) $this->db->lastInsertId();
    }

    private function crearInsumo(int $stock, bool $activo = true, string $estado = 'DISPONIBLE'): int
    {
        $this->db->prepare("INSERT INTO insumo (nombre, stock, costo, estado, activo)
                            VALUES ('Insumo de prueba stock interno', ?, 100, ?, ?)")
            ->execute([$stock, $estado, $activo ? 1 : 0]);
        return $this->insumos[] = (int) $this->db->lastInsertId();
    }

    private function movimiento(string $operacion): array
    {
        $stmt = $this->db->prepare('SELECT tipo, motivo, cantidad, fecha FROM movimiento_inventario WHERE id_operacion = ?');
        $stmt->execute([$operacion]);
        return $stmt->fetch();
    }

    private function stock(string $tabla, int $id): int
    {
        $stmt = $this->db->prepare("SELECT stock FROM $tabla WHERE id = ?");
        $stmt->execute([$id]);
        return (int) $stmt->fetchColumn();
    }

    private function estado(int $insumo): string
    {
        $stmt = $this->db->prepare('SELECT estado FROM insumo WHERE id = ?');
        $stmt->execute([$insumo]);
        return $stmt->fetchColumn();
    }
}
