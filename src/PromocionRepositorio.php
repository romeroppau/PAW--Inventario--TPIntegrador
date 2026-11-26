<?php

// El SQL de las promociones (tabla promocion, migración 016). Contrato: docs/api/contrato-tienda.md,
// sección "Promociones". Las reglas están en la clase Promocion.
// - Todas las consultas son parametrizadas ("?").
// - Las promociones no se borran: terminar una completa terminada_en.
// - Cada promoción es de un lote (migración 028): dura hasta su fecha o hasta que se termina el lote.
class PromocionRepositorio
{
    // La misma regla que la tienda (ProductoRepositorio::VISIBLE_EN_TIENDA).
    private const A_LA_VENTA = Lotes::A_LA_VENTA;

    // Hora de Argentina (-03:00) para creada_en, terminada_en y el CURDATE() de las fechas.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // Las promociones vigentes, para el panel de la veterinaria: con el producto, su lote, quién la
    // publicó y si se ve en la tienda (dado de baja, o sin unidades en el lote, no se ve).
    public function vigentes(): array
    {
        $consulta = $this->db->query(
            "SELECT pr.id, pr.producto_id, p.nombre, p.precio, " . Promocion::PRECIO_FINAL . " AS precio_promocion,
                    pr.porcentaje, DATE_FORMAT(pr.hasta, '%Y-%m-%d') AS hasta, p.stock,
                    lp.id AS lote_id, lp.numero AS lote_numero, lp.codigo AS lote_codigo, DATE_FORMAT(lp.vencimiento, '%Y-%m-%d') AS vencimiento,
                    COALESCE(lp.stock, 0) AS quedan,
                    DATE_FORMAT(pr.creada_en, '%Y-%m-%d %H:%i:%s') AS creada_en, u.nombre AS creada_por,
                    (" . self::A_LA_VENTA . " AND COALESCE(lp.stock, 0) > 0) AS a_la_venta
             FROM promocion pr
             JOIN producto p ON p.id = pr.producto_id
             LEFT JOIN lote_producto lp ON lp.id = pr.lote_id
             LEFT JOIN usuario u ON u.id = pr.creada_por
             WHERE pr.terminada_en IS NULL AND pr.hasta >= CURDATE()
             ORDER BY pr.hasta, p.nombre, pr.id"
        );

        return array_map([$this, 'formatear'], $consulta->fetchAll());
    }

    // El producto al que se le quiere poner una promoción: si existe y si está a la venta. null si no existe.
    public function producto(int $productoId): ?array
    {
        $consulta = $this->db->prepare('SELECT p.id, (' . self::A_LA_VENTA . ') AS a_la_venta FROM producto p WHERE p.id = ?');
        $consulta->execute([$productoId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : ['a_la_venta' => (bool) $fila['a_la_venta']];
    }

    // Publica la promoción sobre el lote y devuelve su id, o ['ya_tiene', promoción vigente] si el
    // producto ya tiene una. Una promoción anterior que ya pasó su fecha se termina sola (al final de
    // su último día), y una cuyo lote se quedó sin unidades, ahora.
    public function crear(int $productoId, int $loteId, int $porcentaje, string $hasta, int $usuarioId): int|array
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare(
                'UPDATE promocion SET terminada_en = TIMESTAMP(hasta + INTERVAL 1 DAY)
                 WHERE producto_id = ? AND terminada_en IS NULL AND hasta < CURDATE()'
            )->execute([$productoId]);
            $this->db->prepare(
                'UPDATE promocion pr JOIN lote_producto lp ON lp.id = pr.lote_id SET pr.terminada_en = NOW()
                 WHERE pr.producto_id = ? AND pr.terminada_en IS NULL AND lp.stock = 0'
            )->execute([$productoId]);

            $vigente = $this->vigenteDe($productoId);
            if ($vigente !== null) {
                $this->db->rollBack();
                return ['ya_tiene', $vigente];
            }

            $this->db->prepare(
                'INSERT INTO promocion (producto_id, lote_id, porcentaje, hasta, creada_en, creada_por) VALUES (?, ?, ?, ?, NOW(), ?)'
            )->execute([$productoId, $loteId, $porcentaje, $hasta, $usuarioId]);
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
            // El lote de la promoción (028) y cuántas unidades le quedan con descuento.
            'lote' => $fila['lote_id'] === null ? null : [
                'id' => (int) $fila['lote_id'],
                // L1, L2… (029) y el número del fabricante.
                'numero' => $fila['lote_numero'] === null ? null : (int) $fila['lote_numero'],
                'nombre' => Lotes::nombre($fila['lote_numero'] === null ? null : (int) $fila['lote_numero'], $fila['lote_codigo']),
                'codigo' => $fila['lote_codigo'],
                'vencimiento' => $fila['vencimiento'],
            ],
            'quedan' => (int) $fila['quedan'],
            'vencimiento' => $fila['vencimiento'],
            'a_la_venta' => (bool) $fila['a_la_venta'],
            'creada_en' => $fila['creada_en'],
            'creada_por' => $fila['creada_por'],
        ];
    }
}
