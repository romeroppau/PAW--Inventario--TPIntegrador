<?php

class StockInternoRepositorio
{
    private const TABLAS = ['PRODUCTO' => 'producto', 'INSUMO' => 'insumo'];

    // MySQL (Aiven) trabaja en UTC: igual que en productos e insumos, los movimientos se guardan
    // con la hora de Argentina (sin horario de verano, siempre -03:00).
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    /**
     * @param array<int, array{tipo:string,id:int,cantidad:int,id_operacion:string}> $items
     * @return array{resultado:string, items?:array}
     *   resultado: APLICADO | DUPLICADA | STOCK_INSUFICIENTE | ITEM_INEXISTENTE | DADO_DE_BAJA
     *              | OPERACION_DISTINTA
     */
    public function descontar(int $usuarioId, array $items): array
    {
        usort($items, fn($a, $b) => [$a['tipo'], $a['id']] <=> [$b['tipo'], $b['id']]);

        $this->db->beginTransaction();
        try {
            $faltantes = [];
            $bajas = [];
            $distintas = [];
            $aplicados = 0;

            foreach ($items as $item) {
                // Primero se comprueba que el ítem exista: si no, el movimiento chocaría con la
                // clave foránea y terminaría en un 500 en lugar de un 404.
                $actual = $this->estadoActual($item);
                if ($actual === null) {
                    $this->db->rollBack();
                    return [
                        'resultado' => 'ITEM_INEXISTENTE',
                        'items' => [['tipo' => $item['tipo'], 'id' => $item['id']]]
                    ];
                }

                // INSERT IGNORE no sirve: ocultaría otros errores. Usamos try/catch por ítem
                // con un SAVEPOINT para no abortar la transacción entera.
                // Un producto (migración 028) o un insumo (029) primero sale de sus lotes y después
                // registra un movimiento por lote: si el id_operacion ya se usó, el ROLLBACK TO
                // SAVEPOINT devuelve también el stock de los lotes.
                // Un reintento se reconoce antes de tocar los lotes (si no, sin stock daría
                // "insuficiente" en lugar de "ya aplicado").
                if ($this->operacionUsada($item['id_operacion'])) {
                    $this->anotarReintento($item, $distintas);
                    continue;
                }

                $this->db->exec('SAVEPOINT item');
                try {
                    $descontado = $this->descontarDeLotes($usuarioId, $item);
                } catch (PDOException $e) {
                    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                        $this->db->exec('ROLLBACK TO SAVEPOINT item');
                        $this->anotarReintento($item, $distintas);
                        continue;
                    }
                    throw $e;
                }

                if (!$descontado) {
                    // Puede haber cambiado desde la lectura de arriba: se vuelve a leer.
                    $actual = $this->estadoActual($item);
                    if (!$actual['activo']) {
                        $bajas[] = ['tipo' => $item['tipo'], 'id' => $item['id']];
                        continue;
                    }
                    $faltantes[] = ['tipo' => $item['tipo'], 'id' => $item['id'], 'stock' => $actual['stock']];
                    continue;
                }
                $aplicados++;
            }

            if ($distintas) {
                $this->db->rollBack();
                return ['resultado' => 'OPERACION_DISTINTA', 'items' => $distintas];
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

    // El id_operacion ya se usó. Si fue para este mismo ítem y cantidad, es un reintento y se saltea.
    // Si no (por ejemplo, la cantidad cambió entre un intento y otro), no se puede dar por aplicado:
    // se anota en $distintas y se rechaza todo el pedido.
    private function anotarReintento(array $item, array &$distintas): void
    {
        $aplicada = $this->cantidadAplicada($item);
        if ($aplicada !== $item['cantidad']) {
            $distintas[] = [
                'tipo' => $item['tipo'],
                'id' => $item['id'],
                'cantidad_aplicada' => $aplicada,
            ];
        }
    }

    private function operacionUsada(string $idOperacion): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM movimiento_inventario WHERE id_operacion = ?');
        $stmt->execute([$idOperacion]);

        return (int) $stmt->fetchColumn() > 0;
    }

    // Los lotes del producto o del insumo del ítem.
    private function lotes(array $item): Lotes
    {
        return $item['tipo'] === 'INSUMO' ? Lotes::deInsumos($this->db) : new Lotes($this->db);
    }

    // Un producto (migración 028) o un insumo (029) sale de sus lotes sin vencer, el que vence primero
    // antes, y registra un movimiento por lote: el primero con el id_operacion del pedido y los demás
    // con "{id_operacion}-lote-{lote}". Los movimientos no se modifican (el usuario inventario solo
    // tiene SELECT e INSERT): por eso se insertan después de saber de qué lotes sale.
    // false si está dado de baja o no alcanza (y no descuenta nada).
    private function descontarDeLotes(int $usuarioId, array $item): bool
    {
        $lotes = $this->lotes($item);
        $actual = $lotes->bloquear($item['id']);
        if ($actual === null || !$actual['activo']) {
            return false;
        }

        $partes = $lotes->sacar($item['id'], $item['cantidad']);
        if ($partes === null) {
            return false;
        }

        foreach ($partes as $i => [$loteId, $tomadas]) {
            $lotes->movimiento($item['id'], $loteId, 'SALIDA', 'CONSULTA', $tomadas, $usuarioId,
                $i === 0 ? $item['id_operacion'] : "{$item['id_operacion']}-lote-$loteId");
        }
        $lotes->sincronizar($item['id']);

        return true;
    }

    // La cantidad que ya se descontó con este id_operacion, si fue para el mismo ítem; si no, 0.
    private function cantidadAplicada(array $item): int
    {
        $stmt = $this->db->prepare(
            'SELECT producto_id, insumo_id, cantidad FROM movimiento_inventario WHERE id_operacion = ?'
        );
        $stmt->execute([$item['id_operacion']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        $columna = $item['tipo'] === 'INSUMO' ? 'insumo_id' : 'producto_id';
        if (!$fila || (int) $fila[$columna] !== $item['id']) {
            return 0;
        }

        // Pudo salir de varios lotes (028 y 029): se suman sus movimientos.
        $stmt = $this->db->prepare('SELECT SUM(cantidad) FROM movimiento_inventario WHERE id_operacion = ? OR id_operacion LIKE ?');
        $stmt->execute([$item['id_operacion'], $item['id_operacion'] . '-lote-%']);

        return (int) $stmt->fetchColumn();
    }

    /** @return array{stock:int, activo:bool}|null null si el ítem no existe */
    private function estadoActual(array $item): ?array
    {
        // El nombre de la tabla sale de la lista fija, nunca del pedido
        $tabla = self::TABLAS[$item['tipo']];
        $stmt = $this->db->prepare("SELECT stock, activo FROM $tabla WHERE id = ?");
        $stmt->execute([$item['id']]);
        $fila = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fila) {
            return null;
        }
        // Se informa lo que se puede usar: sin los lotes vencidos (028 y 029).
        $stock = $this->lotes($item)->stockVendible($item['id']);

        return ['stock' => $stock, 'activo' => (bool) $fila['activo']];
    }
}