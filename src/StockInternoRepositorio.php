<?php

class StockInternoRepositorio
{
    private const TABLAS = ['PRODUCTO' => 'producto', 'INSUMO' => 'insumo'];

    public function __construct(private PDO $db)
    {
    }

    /**
     * @param array<int, array{tipo:string,id:int,cantidad:int,id_operacion:string}> $items
     * @return array{resultado:string, items?:array}
     *   resultado: APLICADO | DUPLICADA | STOCK_INSUFICIENTE | ITEM_INEXISTENTE | DADO_DE_BAJA
     */
    public function descontar(int $usuarioId, array $items): array
    {
        usort($items, fn($a, $b) => [$a['tipo'], $a['id']] <=> [$b['tipo'], $b['id']]);

        $this->db->beginTransaction();
        try {
            $faltantes = [];
            $bajas = [];
            $aplicados = 0;

            foreach ($items as $item) {
                // INSERT IGNORE no sirve: ocultaría otros errores. Usamos try/catch por ítem
                // con un SAVEPOINT para no abortar la transacción entera.
                $this->db->exec('SAVEPOINT item');
                try {
                    $this->insertarMovimiento($usuarioId, $item);
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        $this->db->exec('ROLLBACK TO SAVEPOINT item');
                        continue;   // este ítem ya se aplicó: se saltea
                    }
                    throw $e;
                }

                if (!$this->descontarItem($item)) {
                    $actual = $this->estadoActual($item);
                    if ($actual === null) {
                        $this->db->rollBack();
                        return [
                            'resultado' => 'ITEM_INEXISTENTE',
                            'items' => [['tipo' => $item['tipo'], 'id' => $item['id']]]
                        ];
                    }
                    if (!$actual['activo']) {
                        $bajas[] = ['tipo' => $item['tipo'], 'id' => $item['id']];
                        continue;
                    }
                    $faltantes[] = ['tipo' => $item['tipo'], 'id' => $item['id'], 'stock' => $actual['stock']];
                    continue;
                }
                $aplicados++;
            }

            if ($bajas) {
                $this->db->rollBack();
                return ['resultado' => 'DADO_DE_BAJA', 'items' => $bajas];
            }

            if ($faltantes) {
                $this->db->rollBack();
                return ['resultado' => 'STOCK_INSUFICIENTE', 'items' => $faltantes];
            }

            $this->db->commit();
            // aplicado:false solo si TODOS los ítems ya estaban aplicados
            return ['resultado' => $aplicados > 0 ? 'APLICADO' : 'DUPLICADA'];

        } catch (PDOException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function descontarItem(array $item): bool
    {
        if ($item['tipo'] === 'INSUMO') {
            // MySQL aplica el SET de izquierda a derecha: el IF ya ve el stock nuevo.
            $sql = "UPDATE insumo
                    SET stock = stock - ?,
                        estado = IF(stock = 0, 'EN_FALTA', 'DISPONIBLE')
                    WHERE id = ? AND activo = 1 AND stock >= ?";
        } else {
            $sql = "UPDATE producto SET stock = stock - ? WHERE id = ? AND activo = 1 AND stock >= ?";
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

    /** @return array{stock:int, activo:bool}|null null si el ítem no existe */
    private function estadoActual(array $item): ?array
    {
        // El nombre de la tabla sale de la lista fija, nunca del pedido
        $tabla = self::TABLAS[$item['tipo']];
        $stmt = $this->db->prepare("SELECT stock, activo FROM $tabla WHERE id = ?");
        $stmt->execute([$item['id']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        return $fila ? ['stock' => (int) $fila['stock'], 'activo' => (bool) $fila['activo']] : null;
    }
}