<?php

use PHPUnit\Framework\TestCase;

// Retiro manual de stock de un producto (POST /api/inventario/productos/{id}/retiro) contra un
// MySQL real: resta solo si alcanza, no toca los productos dados de baja y deja la SALIDA con
// motivo AJUSTE. Cada prueba crea sus productos y los borra al final.
class ProductoRetiroTest extends TestCase
{
    private PDO $db;
    private ProductoRepositorio $repo;
    private int $usuarioId;
    private array $productos = [];
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

        // Los movimientos tienen clave foránea a usuario: si la base está vacía, se crea uno.
        $id = $this->db->query('SELECT id FROM usuario ORDER BY id LIMIT 1')->fetchColumn();
        if ($id === false) {
            $this->db->exec("INSERT INTO usuario (nombre, email, password_hash, rol)
                             VALUES ('Prueba retiro', 'retiro-producto@prueba.test', 'x', 'VETERINARIO')");
            $id = $this->usuarioCreado = (int) $this->db->lastInsertId();
        }
        $this->usuarioId = (int) $id;

        $this->repo = new ProductoRepositorio($this->db);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->productos as $id) {
            $this->db->prepare('DELETE FROM movimiento_inventario WHERE producto_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM lote_producto WHERE producto_id = ?')->execute([$id]);
            $this->db->prepare('DELETE FROM producto WHERE id = ?')->execute([$id]);
        }
        if ($this->usuarioCreado !== null) {
            $this->db->prepare('DELETE FROM usuario WHERE id = ?')->execute([$this->usuarioCreado]);
        }
    }

    public function testRestaElStockYRegistraLaSalidaPorAjuste(): void
    {
        $producto = $this->crearProducto(10);

        $this->assertSame(7, $this->repo->retirarStock($producto, 3, $this->usuarioId));
        $this->assertSame(7, $this->stock($producto));

        $mov = $this->db->query("SELECT tipo, motivo, cantidad, usuario_id FROM movimiento_inventario
                                 WHERE producto_id = $producto")->fetchAll();
        $this->assertCount(1, $mov);
        $this->assertSame(['SALIDA', 'AJUSTE', 3, $this->usuarioId],
            [$mov[0]['tipo'], $mov[0]['motivo'], (int) $mov[0]['cantidad'], (int) $mov[0]['usuario_id']]);
    }

    public function testSePuedeRetirarTodoElStock(): void
    {
        $producto = $this->crearProducto(4);

        $this->assertSame(0, $this->repo->retirarStock($producto, 4, $this->usuarioId));
    }

    public function testSiNoAlcanzaNoCambiaNada(): void
    {
        $producto = $this->crearProducto(2);

        $this->assertSame(['insuficiente', 2], $this->repo->retirarStock($producto, 3, $this->usuarioId));
        $this->assertSame(2, $this->stock($producto));
        $this->assertSame(0, $this->movimientos($producto));
    }

    public function testUnProductoDadoDeBajaNoSeToca(): void
    {
        $producto = $this->crearProducto(10, false);

        $this->assertSame('baja', $this->repo->retirarStock($producto, 1, $this->usuarioId));
        $this->assertSame(10, $this->stock($producto));
        $this->assertSame(0, $this->movimientos($producto));
    }

    public function testUnProductoQueNoExisteNoSeToca(): void
    {
        $this->assertSame('baja', $this->repo->retirarStock(999999999, 1, $this->usuarioId));
    }

    // Migración 028: sin elegir lote, sale primero de los vencidos; eligiendo, de ese lote.
    public function testSinLoteSaleDelVencidoPrimeroYConLoteDeEse(): void
    {
        $producto = $this->crearProducto(5, true, '2020-01-01');
        $nuevo = $this->agregarLote($producto, 8, '2099-12-31');

        $this->assertSame(10, $this->repo->retirarStock($producto, 3, $this->usuarioId));
        $this->assertSame([2, 8], [$this->stockLote($producto, '2020-01-01'), $this->stockLote($producto, '2099-12-31')]);

        $this->assertSame(8, $this->repo->retirarStock($producto, 2, $this->usuarioId, $nuevo));
        $this->assertSame([2, 6], [$this->stockLote($producto, '2020-01-01'), $this->stockLote($producto, '2099-12-31')]);
        $this->assertSame(['insuficiente', 6], $this->repo->retirarStock($producto, 7, $this->usuarioId, $nuevo));
        $this->assertSame('lote_inexistente', $this->repo->retirarStock($producto, 1, $this->usuarioId, 999999999));
    }

    // Cargar stock: en un lote que ya existe, en uno nuevo o (sin elegir) en el que no vence.
    public function testCargarEnUnLoteNuevoYEnUnoQueYaExiste(): void
    {
        $producto = $this->crearProducto(5, true, '2099-01-01');

        $r = $this->repo->cargarStock($producto, 4, $this->usuarioId, null, ['codigo' => 'L-77', 'vencimiento' => '2099-06-30']);
        $this->assertSame(9, $r['stock']);
        $this->assertSame(['codigo' => 'L-77', 'vencimiento' => '2099-06-30', 'stock' => 4],
            ['codigo' => $r['lote']['codigo'], 'vencimiento' => $r['lote']['vencimiento'], 'stock' => $r['lote']['stock']]);

        $r = $this->repo->cargarStock($producto, 1, $this->usuarioId, $r['lote']['id']);
        $this->assertSame([10, 5], [$r['stock'], $r['lote']['stock']]);

        $r = $this->repo->cargarStock($producto, 2, $this->usuarioId);
        $this->assertSame([12, null], [$r['stock'], $r['lote']['vencimiento']]);

        // El producto vence cuando vence su primer lote con stock, y los movimientos dicen el lote
        // (L-77 y el que no vence; el primero lo creó la prueba, sin movimiento).
        $this->assertSame('2099-01-01', $this->db->query("SELECT fecha_vencimiento FROM producto WHERE id = $producto")->fetchColumn());
        $this->assertSame(2, (int) $this->db->query("SELECT COUNT(DISTINCT lote_id) FROM movimiento_inventario WHERE producto_id = $producto")->fetchColumn());
    }

    // El producto con su stock en un lote (con ese vencimiento).
    private function crearProducto(int $stock, bool $activo = true, ?string $vencimiento = null): int
    {
        $this->db->prepare("INSERT INTO producto (nombre, precio, stock, categoria, especie_destino, etapa_vida, activo)
                            VALUES ('Producto de prueba retiro', 1000, ?, 'MEDICAMENTO', 'TODAS', 'TODAS', ?)")
            ->execute([$stock, $activo ? 1 : 0]);
        $id = $this->productos[] = (int) $this->db->lastInsertId();
        $this->agregarLote($id, $stock, $vencimiento);

        return $id;
    }

    private function agregarLote(int $producto, int $stock, ?string $vencimiento): int
    {
        $this->db->prepare('INSERT INTO lote_producto (producto_id, vencimiento, stock, creado_en) VALUES (?, ?, ?, NOW())')
            ->execute([$producto, $vencimiento, $stock]);
        $lote = (int) $this->db->lastInsertId();
        (new Lotes($this->db))->sincronizar($producto);

        return $lote;
    }

    private function stockLote(int $producto, string $vencimiento): int
    {
        $consulta = $this->db->prepare('SELECT stock FROM lote_producto WHERE producto_id = ? AND vencimiento = ?');
        $consulta->execute([$producto, $vencimiento]);

        return (int) $consulta->fetchColumn();
    }

    private function stock(int $id): int
    {
        return (int) $this->db->query("SELECT stock FROM producto WHERE id = $id")->fetchColumn();
    }

    private function movimientos(int $id): int
    {
        return (int) $this->db->query("SELECT COUNT(*) FROM movimiento_inventario WHERE producto_id = $id")->fetchColumn();
    }
}
