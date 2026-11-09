<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/StockInternoRepositorio.php';

class StockInternoRepositorioTest extends TestCase
{
    private PDO $db;
    private StockInternoRepositorio $repositorio;
    private int $productoId;
    private int $usuarioId;
    private string $idOperacion;

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
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped(
                'No se pudo conectar con MySQL: ' . $e->getMessage()
            );
        }

        $this->repositorio = new StockInternoRepositorio($this->db);

        // Necesitamos un usuario válido por la clave foránea.
        $stmt = $this->db->query(
            'SELECT id FROM usuario LIMIT 1'
        );

        $usuarioExistente = $stmt->fetch();

        if (!$usuarioExistente) {
            $this->markTestSkipped(
                'Se necesita al menos un usuario de prueba en la tabla usuario.'
            );
        }

        $this->usuarioId = (int) $usuarioExistente['id'];

        // Crear un producto exclusivo para esta prueba.
        $stmt = $this->db->prepare("
        INSERT INTO producto (
            nombre,
            precio,
            stock,
            categoria,
            especie_destino,
            etapa_vida
        ) VALUES (
            'Producto de prueba stock interno',
            1000,
            10,
            'MEDICAMENTO',
            'TODAS',
            'TODAS'
        )
        ");

        $stmt->execute();

        $this->productoId = (int) $this->db->lastInsertId();

        // Identificador único para cada ejecución.
        $this->idOperacion = bin2hex(random_bytes(16));
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }

        if (isset($this->productoId)) {
            $stmt = $this->db->prepare("
            DELETE FROM movimiento_inventario
            WHERE producto_id = ?
        ");
            $stmt->execute([$this->productoId]);

            $stmt = $this->db->prepare("
            DELETE FROM producto
            WHERE id = ?
        ");
            $stmt->execute([$this->productoId]);
        }
    }

    public function testDescuentaStockYRegistraMovimiento(): void
    {
        $resultado = $this->repositorio->descontarProducto(
            $this->productoId,
            3,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertTrue($resultado['aplicado']);

        // Verificar que el stock pasó de 10 a 7.
        $stmt = $this->db->prepare(
            'SELECT stock FROM producto WHERE id = ?'
        );
        $stmt->execute([$this->productoId]);

        self::assertSame(7, (int) $stmt->fetchColumn());

        // Verificar el movimiento registrado.
        $stmt = $this->db->prepare("
            SELECT producto_id, insumo_id, tipo, cantidad,
                   motivo, usuario_id, id_operacion
            FROM movimiento_inventario
            WHERE id_operacion = ?
        ");
        $stmt->execute([$this->idOperacion]);

        $movimiento = $stmt->fetch();

        self::assertNotFalse($movimiento);
        self::assertSame($this->productoId, (int) $movimiento['producto_id']);
        self::assertNull($movimiento['insumo_id']);
        self::assertSame('SALIDA', $movimiento['tipo']);
        self::assertSame(3, (int) $movimiento['cantidad']);
        self::assertSame('CONSULTA', $movimiento['motivo']);
        self::assertSame($this->usuarioId, (int) $movimiento['usuario_id']);
    }

    public function testNoPermiteDescontarMasStockDelDisponible(): void
    {
        $resultado = $this->repositorio->descontarProducto(
            $this->productoId,
            15,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertFalse($resultado['aplicado']);

        $stmt = $this->db->prepare(
            'SELECT stock FROM producto WHERE id = ?'
        );
        $stmt->execute([$this->productoId]);

        self::assertSame(10, (int) $stmt->fetchColumn());

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM movimiento_inventario WHERE id_operacion = ?'
        );
        $stmt->execute([$this->idOperacion]);

        self::assertSame(0, (int) $stmt->fetchColumn());

        self::assertSame(
            'STOCK_INSUFICIENTE',
            $resultado['motivo']
        );
    }

    public function testRechazaProductoInexistente(): void
    {
        $resultado = $this->repositorio->descontarProducto(
            999999999,
            1,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertFalse($resultado['aplicado']);

        self::assertSame(
            'PRODUCTO_INEXISTENTE',
            $resultado['motivo']
        );
    }

    public function testNoDescuentaDosVecesLaMismaOperacion(): void
    {
        $primeraRespuesta = $this->repositorio->descontarProducto(
            $this->productoId,
            2,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertTrue($primeraRespuesta['aplicado']);

        $segundaRespuesta = $this->repositorio->descontarProducto(
            $this->productoId,
            2,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertFalse($segundaRespuesta['aplicado']);
        self::assertSame(
            'OPERACION_DUPLICADA',
            $segundaRespuesta['motivo']
        );

        $stmt = $this->db->prepare(
            'SELECT stock FROM producto WHERE id = ?'
        );
        $stmt->execute([$this->productoId]);

        // Debe quedar en 8, no en 6.
        self::assertSame(8, (int) $stmt->fetchColumn());
    }
    public function testRechazaCantidadInvalida(): void
    {
        $resultado = $this->repositorio->descontarProducto(
            $this->productoId,
            0,
            $this->usuarioId,
            $this->idOperacion
        );

        self::assertFalse($resultado['aplicado']);

        self::assertSame(
            'DATOS_INVALIDOS',
            $resultado['motivo']
        );

        // El stock debe permanecer intacto.
        $stmt = $this->db->prepare(
            'SELECT stock FROM producto WHERE id = ?'
        );
        $stmt->execute([$this->productoId]);

        self::assertSame(10, (int) $stmt->fetchColumn());
    }
    public function testReintentoConItemNuevoDescuentaSoloElNuevo(): void
    {
        $sufijo = uniqid();
        $productoA = $this->crearProducto(stock: 10);
        $productoB = $this->crearProducto(stock: 10);
        $repo = new StockInternoRepositorio($this->db);

        $itemA = [
            'tipo' => 'PRODUCTO',
            'id' => $productoA,
            'cantidad' => 2,
            'id_operacion' => "t-$sufijo-A"
        ];
        $itemB = [
            'tipo' => 'PRODUCTO',
            'id' => $productoB,
            'cantidad' => 3,
            'id_operacion' => "t-$sufijo-B"
        ];

        // Primer intento: solo A
        $r1 = $repo->descontar($this->usuarioId, [$itemA]);
        $this->assertSame('APLICADO', $r1['resultado']);

        // Reintento con A (ya aplicado) + B (nuevo)
        $r2 = $repo->descontar($this->usuarioId, [$itemA, $itemB]);
        $this->assertSame('APLICADO', $r2['resultado']);

        $this->assertSame(8, $this->stockProducto($productoA), 'A no se descuenta dos veces');
        $this->assertSame(7, $this->stockProducto($productoB), 'B sí se descuenta');
    }

    public function testLoteTotalmenteRepetidoDevuelveDuplicada(): void
    {
        $productoA = $this->crearProducto(stock: 10);
        $repo = new StockInternoRepositorio($this->db);
        $item = [
            'tipo' => 'PRODUCTO',
            'id' => $productoA,
            'cantidad' => 2,
            'id_operacion' => 't-' . uniqid()
        ];

        $repo->descontar($this->usuarioId, [$item]);
        $r = $repo->descontar($this->usuarioId, [$item]);

        $this->assertSame('DUPLICADA', $r['resultado']);
        $this->assertSame(8, $this->stockProducto($productoA));
    }

    public function testItemNuevoSinStockNoDescuentaNada(): void
    {
        $productoA = $this->crearProducto(stock: 10);
        $productoB = $this->crearProducto(stock: 1);
        $repo = new StockInternoRepositorio($this->db);
        $sufijo = uniqid();

        $itemA = ['tipo' => 'PRODUCTO', 'id' => $productoA, 'cantidad' => 2, 'id_operacion' => "t-$sufijo-A"];
        $itemB = ['tipo' => 'PRODUCTO', 'id' => $productoB, 'cantidad' => 5, 'id_operacion' => "t-$sufijo-B"];

        $r = $repo->descontar($this->usuarioId, [$itemA, $itemB]);

        $this->assertSame('STOCK_INSUFICIENTE', $r['resultado']);
        $this->assertSame(10, $this->stockProducto($productoA));
        $this->assertSame(1, $this->stockProducto($productoB));
    }
}
