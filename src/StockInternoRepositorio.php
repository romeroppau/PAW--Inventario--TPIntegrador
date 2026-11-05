<?php

class StockInternoRepositorio
{
    private const TABLAS = ['PRODUCTO' => 'producto', 'INSUMO' => 'insumo'];

    public function __construct(private PDO $db) {}

    /**
     * @param array<int, array{tipo:string,id:int,cantidad:int,id_operacion:string}> $items
     * @return array{resultado:string, items?:array}
     *   resultado: APLICADO | DUPLICADA | STOCK_INSUFICIENTE | ITEM_INEXISTENTE
     */
    public function descontar(int $usuarioId, array $items): array
    {
        // Orden fijo: dos lotes simultáneos bloquean filas en el mismo orden (evita deadlocks)
        usort($items, fn($a, $b) => [$a['tipo'], $a['id']] <=> [$b['tipo'], $b['id']]);

        $this->db->beginTransaction();
        try {
            $faltantes = [];

            foreach ($items as $item) {
                // El INSERT va primero: si el id_operacion existe, salta 1062 y no se toca el stock
                $this->insertarMovimiento($usuarioId, $item);

                if (!$this->descontarItem($item)) {
                    $stock = $this->stockActual($item);
                    if ($stock === null) {
                        $this->db->rollBack();
                        return [
                            'resultado' => 'ITEM_INEXISTENTE',
                            'items' => [['tipo' => $item['tipo'], 'id' => $item['id']]],
                        ];
                    }
                    $faltantes[] = ['tipo' => $item['tipo'], 'id' => $item['id'], 'stock' => $stock];
                }
            }

            if ($faltantes) {
                $this->db->rollBack();   // también deshace los movimientos ya insertados
                return ['resultado' => 'STOCK_INSUFICIENTE', 'items' => $faltantes];
            }

            $this->db->commit();
            return ['resultado' => 'APLICADO'];

        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['resultado' => 'DUPLICADA'];
            }
            throw $e;   // el controlador responde 500 genérico, sin detalles
        }
    }

    private function descontarItem(array $item): bool
    {
        if ($item['tipo'] === 'INSUMO') {
            // MySQL aplica el SET de izquierda a derecha: el IF ya ve el stock nuevo.
            $sql = "UPDATE insumo
                    SET stock = stock - ?,
                        estado = IF(stock = 0, 'EN_FALTA', 'DISPONIBLE')
                    WHERE id = ? AND stock >= ?";
        } else {
            $sql = "UPDATE producto SET stock = stock - ? WHERE id = ? AND stock >= ?";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$item['cantidad'], $item['id'], $item['cantidad']]);
        return $stmt->rowCount() === 1;
    }

    private function insertarMovimiento(int $usuarioId, array $item): void
    {
        $esInsumo = $item['tipo'] === 'INSUMO';

        // Exactamente uno de los dos ids (regla de la migración 003)
        $stmt = $this->db->prepare(
            "INSERT INTO movimiento_inventario
                (producto_id, insumo_id, tipo, cantidad, motivo, usuario_id, id_operacion)
             VALUES (?, ?, 'SALIDA', ?, 'CONSULTA', ?, ?)"
        );
        $stmt->execute([
            $esInsumo ? null : $item['id'],
            $esInsumo ? $item['id'] : null,
            $item['cantidad'],
            $usuarioId,
            $item['id_operacion'],
        ]);
    }

    private function stockActual(array $item): ?int
    {
        // El nombre de la tabla sale de la lista fija, nunca del pedido
        $tabla = self::TABLAS[$item['tipo']];
        $stmt = $this->db->prepare("SELECT stock FROM $tabla WHERE id = ?");
        $stmt->execute([$item['id']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? (int) $fila['stock'] : null;
    }
}