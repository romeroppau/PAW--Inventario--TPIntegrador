<?php

// El SQL de las promociones (tabla promocion, migración 016). Contrato: docs/api/contrato-tienda.md,
// sección "Promociones". Las reglas están en la clase Promocion.
// - Todas las consultas son parametrizadas ("?").
// - Las promociones no se borran: terminar una completa terminada_en.
class PromocionRepositorio
{
    // La misma regla que la tienda (ProductoRepositorio::VISIBLE_EN_TIENDA).
    private const A_LA_VENTA = 'p.activo = 1 AND (p.fecha_vencimiento IS NULL OR p.fecha_vencimiento >= CURDATE())';

    // Hora de Argentina (-03:00) para creada_en, terminada_en y el CURDATE() de las fechas.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // Las promociones vigentes, para el panel de la veterinaria: con el producto, quién la publicó y
    // si el producto sigue a la venta (dado de baja o sin stock, la promoción no se ve en la tienda).
    public function vigentes(): array
    {
        $consulta = $this->db->query(
            "SELECT pr.id, pr.producto_id, p.nombre, p.precio, " . Promocion::PRECIO_FINAL . " AS precio_promocion,
                    pr.porcentaje, DATE_FORMAT(pr.hasta, '%Y-%m-%d') AS hasta, p.stock,
                    DATE_FORMAT(p.fecha_vencimiento, '%Y-%m-%d') AS vencimiento,
                    DATE_FORMAT(pr.creada_en, '%Y-%m-%d %H:%i:%s') AS creada_en, u.nombre AS creada_por,
                    (" . self::A_LA_VENTA . ") AS a_la_venta
             FROM promocion pr
             JOIN producto p ON p.id = pr.producto_id
             LEFT JOIN usuario u ON u.id = pr.creada_por
             WHERE pr.terminada_en IS NULL AND pr.hasta >= CURDATE()
             ORDER BY pr.hasta, p.nombre, pr.id"
        );

        return array_map([$this, 'formatear'], $consulta->fetchAll());
    }

    // El producto al que se le quiere poner una promoción: si existe, si está a la venta y cuándo
    // vence. null si no existe.
    public function producto(int $productoId): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT p.id, (" . self::A_LA_VENTA . ") AS a_la_venta, DATE_FORMAT(p.fecha_vencimiento, '%Y-%m-%d') AS vencimiento
             FROM producto p WHERE p.id = ?"
        );
        $consulta->execute([$productoId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : [
            'a_la_venta' => (bool) $fila['a_la_venta'],
            'vencimiento' => $fila['vencimiento'],
        ];
    }

    // Publica la promoción y devuelve su id, o ['ya_tiene', promoción vigente] si el producto ya
    // tiene una. Una promoción anterior que ya pasó su fecha se termina sola (al final de su último día).
    public function crear(int $productoId, int $porcentaje, string $hasta, int $usuarioId): int|array
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE promocion SET terminada_en = TIMESTAMP(hasta + INTERVAL 1 DAY)
                 WHERE producto_id = ? AND terminada_en IS NULL AND hasta < CURDATE()'
            )->execute([$productoId]);

            $vigente = $this->vigenteDe($productoId);
            if ($vigente !== null) {
                $this->db->rollBack();
                return ['ya_tiene', $vigente];
            }

            $this->db->prepare(
                'INSERT INTO promocion (producto_id, porcentaje, hasta, creada_en, creada_por) VALUES (?, ?, ?, NOW(), ?)'
            )->execute([$productoId, $porcentaje, $hasta, $usuarioId]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (PDOException $error) {
            $this->db->rollBack();
            // Dos "Publicitar" al mismo tiempo: la base rechaza el segundo (índice único).
            if ($error->getCode() === '23000' && ($vigente = $this->vigenteDe($productoId)) !== null) {
                return ['ya_tiene', $vigente];
            }
            throw $error;
        }

        return $id;
    }

    // Termina una promoción vigente (deja de verse en la tienda ahora). false si no hay una vigente con ese id.
    public function terminar(int $id): bool
    {
        $consulta = $this->db->prepare(
            'UPDATE promocion SET terminada_en = NOW() WHERE id = ? AND terminada_en IS NULL AND hasta >= CURDATE()'
        );
        $consulta->execute([$id]);

        return $consulta->rowCount() > 0;
    }

    // Una promoción vigente por su id, con el formato del panel, o null.
    public function buscar(int $id): ?array
    {
        foreach ($this->vigentes() as $promocion) {
            if ($promocion['id'] === $id) {
                return $promocion;
            }
        }

        return null;
    }

    // La promoción vigente de un producto ({id, porcentaje, hasta}), o null.
    private function vigenteDe(int $productoId): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT id, porcentaje, DATE_FORMAT(hasta, '%Y-%m-%d') AS hasta FROM promocion
             WHERE producto_id = ? AND terminada_en IS NULL AND hasta >= CURDATE()"
        );
        $consulta->execute([$productoId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : [
            'id' => (int) $fila['id'],
            'porcentaje' => (int) $fila['porcentaje'],
            'hasta' => $fila['hasta'],
        ];
    }

    private function formatear(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'producto_id' => (int) $fila['producto_id'],
            'nombre' => $fila['nombre'],
            'precio' => (float) $fila['precio'],
            'porcentaje' => (int) $fila['porcentaje'],
            'precio_promocion' => (float) $fila['precio_promocion'],
            'hasta' => $fila['hasta'],
            'stock' => (int) $fila['stock'],
            'vencimiento' => $fila['vencimiento'],
            'a_la_venta' => (bool) $fila['a_la_venta'],
            'creada_en' => $fila['creada_en'],
            'creada_por' => $fila['creada_por'],
        ];
    }
}
