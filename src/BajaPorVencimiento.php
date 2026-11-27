<?php

// Baja de los lotes vencidos (27/11, devolución del profesor: "el reporte de stock cuenta el stock
// vencido"). Contrato: docs/api/contrato.md, "POST /api/inventario/tareas/vencer-lotes".
//
// Todos los días, el motor diario (GitHub Actions) llama a la tarea. Por cada lote vencido que todavía
// tiene stock, de productos y de insumos:
//   - el lote queda en 0 y el stock del producto o insumo baja (Lotes::sincronizar);
//   - queda un movimiento SALIDA con motivo VENCIMIENTO (migración 031) con todo lo que se dio de baja,
//     que se ve en Movimientos;
//   - cada veterinaria recibe un aviso por la campana.
// Un lote vence al terminar el día de su vencimiento: el que vence hoy todavía se usa y se da de baja
// mañana. Cada lote va en su propia transacción y vuelve a mirar el lote bloqueado: si dos corridas
// coinciden, la segunda no encuentra nada. El id_operacion del movimiento es fijo por lote (no se
// puede repetir). Los avisos de los últimos dos días se vuelven a mandar en cada corrida: Backend no
// repite un aviso con el mismo id_operacion, así que si Backend no respondió, sale en la próxima.
class BajaPorVencimiento
{
    // [tabla de lotes, columna del producto o insumo, tabla del producto o insumo, columna del lote en movimiento_inventario]
    private const TABLAS = [
        'producto' => ['lote_producto', 'producto_id', 'producto', 'lote_id'],
        'insumo' => ['lote_insumo', 'insumo_id', 'insumo', 'lote_insumo_id'],
    ];

    public function __construct(private PDO $db)
    {
        // CURDATE() y NOW() en hora de Argentina (la base de producción está en UTC), como los repositorios.
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // El id_operacion del movimiento de baja de un lote (CHAR(36) en la base: "vencimiento-insumo-123456").
    public static function idOperacion(string $de, int $loteId): string
    {
        return "vencimiento-$de-$loteId";
    }

    // Da de baja todo lo vencido y manda los avisos. Devuelve cuántos lotes y unidades se dieron de
    // baja de cada tipo y cuántos avisos nuevos salieron.
    public function ejecutar(DateTimeImmutable $ahora): array
    {
        $resultado = $this->darDeBajaVencidos();
        $resultado['avisos'] = $this->mandarAvisos($ahora);

        return $resultado;
    }

    // Da de baja los lotes vencidos, sin los avisos: {lotes_productos, lotes_insumos, unidades}.
    public function darDeBajaVencidos(): array
    {
        $resultado = ['lotes_productos' => 0, 'lotes_insumos' => 0, 'unidades' => 0];

        foreach (array_keys(self::TABLAS) as $de) {
            foreach ($this->vencidos($de) as $loteId) {
                $cantidad = $this->darDeBaja($de, $loteId);
                if ($cantidad > 0) {
                    $resultado[$de === 'producto' ? 'lotes_productos' : 'lotes_insumos']++;
                    $resultado['unidades'] += $cantidad;
                }
            }
        }

        return $resultado;
    }

    // Los lotes vencidos (antes de hoy) con stock.
    private function vencidos(string $de): array
    {
        [$tabla] = self::TABLAS[$de];

        return array_map('intval', $this->db->query(
            "SELECT id FROM $tabla WHERE stock > 0 AND vencimiento < CURDATE() ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
    }

    // Da de baja un lote, en su transacción. Devuelve cuántas unidades (0 si ya no había nada).
    public function darDeBaja(string $de, int $loteId): int
    {
        [$tabla, $columna] = self::TABLAS[$de];
        $lotes = new Lotes($this->db, $de);

        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare("SELECT $columna FROM $tabla WHERE id = ?");
            $consulta->execute([$loteId]);
            $item = $consulta->fetchColumn();
            if ($item === false) {
                $this->db->rollBack();
                return 0;
            }
            $item = (int) $item;

            // Primero el producto o insumo (el mismo orden que una carga o una consulta: no se
            // cruzan) y después el lote, ya bloqueado: si otra corrida lo dio de baja, tiene 0.
            $lotes->bloquear($item);
            $consulta = $this->db->prepare("SELECT stock FROM $tabla WHERE id = ? AND stock > 0 AND vencimiento < CURDATE() FOR UPDATE");
            $consulta->execute([$loteId]);
            $cantidad = (int) $consulta->fetchColumn();
            if ($cantidad === 0) {
                $this->db->rollBack();
                return 0;
            }

            $lotes->restar($loteId, $cantidad);
            $lotes->movimiento($item, $loteId, 'SALIDA', 'VENCIMIENTO', $cantidad, null, self::idOperacion($de, $loteId));
            $lotes->sincronizar($item);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $cantidad;
    }

    // Un aviso a cada veterinaria por cada baja de los últimos dos días (solo la campana). Devuelve
    // cuántos avisos nuevos quedaron.
    private function mandarAvisos(DateTimeImmutable $ahora): int
    {
        $veterinarias = array_map('intval', $this->db->query(
            "SELECT id FROM usuario WHERE rol = 'VETERINARIO' ORDER BY id"
        )->fetchAll(PDO::FETCH_COLUMN));
        $nuevos = 0;

        foreach ($this->bajasRecientes() as $baja) {
            $mensaje = self::mensaje($baja);
            foreach ($veterinarias as $veterinariaId) {
                $id = $baja['id_operacion'] . "-vet-$veterinariaId";
                if (Avisos::mandar($veterinariaId, $mensaje, $id, $ahora, Avisos::SOLO_CAMPANA, 'STOCK_BAJO') === true) {
                    $nuevos++;
                }
            }
        }

        return $nuevos;
    }

    // Las bajas por vencimiento de los últimos dos días, con lo que hace falta para el aviso.
    public function bajasRecientes(): array
    {
        $bajas = [];
        foreach (self::TABLAS as $de => [$tabla, $columna, $padre, $columnaMovimiento]) {
            $filas = $this->db->query(
                "SELECT m.id_operacion, m.cantidad, x.nombre, x.stock, l.numero, l.codigo,
                        DATE_FORMAT(l.vencimiento, '%d/%m/%Y') AS vencimiento
                 FROM movimiento_inventario m
                 JOIN $tabla l ON l.id = m.$columnaMovimiento
                 JOIN $padre x ON x.id = m.$columna
                 WHERE m.motivo = 'VENCIMIENTO' AND m.$columna IS NOT NULL AND m.fecha >= NOW() - INTERVAL 2 DAY
                 ORDER BY m.id"
            )->fetchAll();
            foreach ($filas as $fila) {
                $bajas[] = $fila + ['de' => $de];
            }
        }

        return $bajas;
    }

    // "Dimos de baja el lote L2 (FL-2612) del producto Vacuna X: 8 unidades vencieron el 25/11/2026.
    // Stock actual: 12. Lo ves en Movimientos."
    public static function mensaje(array $baja): string
    {
        $lote = Lotes::nombre($baja['numero'] === null ? null : (int) $baja['numero'], $baja['codigo']);
        $cantidad = (int) $baja['cantidad'];
        $unidades = $cantidad === 1 ? '1 unidad venció' : "$cantidad unidades vencieron";

        return "Dimos de baja el lote $lote del {$baja['de']} {$baja['nombre']}: $unidades el {$baja['vencimiento']}. "
            . 'Stock actual: ' . (int) $baja['stock'] . '. Lo ves en Movimientos.';
    }
}
