<?php

// Los lotes de los productos (tabla lote_producto, migración 028). Contrato: docs/api/contrato.md,
// sección "Lotes". Es la ÚNICA clase que cambia el stock de un lote.
// - Cada lote tiene su vencimiento (o ninguno) y su stock. producto.stock es la suma de los lotes y
//   producto.fecha_vencimiento, el del lote con stock que vence primero: los actualiza sincronizar(),
//   en la misma transacción que cambia el lote.
// - Se vende y se usa en las consultas lo que NO está vencido, empezando por el lote que vence primero
//   (los que no vencen, al final).
// - Un lote vencido con stock queda en el panel (y en Reportes) para retirarlo; no se vende.
// Los métodos que cambian stock se llaman siempre dentro de una transacción.
class Lotes
{
    public const CODIGO_MAXIMO = 40;

    // Un lote "l" que se puede vender o usar hoy.
    public const VENDIBLE = '(l.vencimiento IS NULL OR l.vencimiento >= CURDATE())';

    // Para las consultas que traen productos "p": lo que se puede vender hoy y el próximo vencimiento.
    public const STOCK_VENDIBLE = '(SELECT COALESCE(SUM(l.stock), 0) FROM lote_producto l
                                    WHERE l.producto_id = p.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE()))';
    public const PROXIMO_VENCIMIENTO = "(SELECT DATE_FORMAT(MIN(l.vencimiento), '%Y-%m-%d') FROM lote_producto l
                                         WHERE l.producto_id = p.id AND l.stock > 0 AND l.vencimiento >= CURDATE())";

    // Un producto está a la venta si está activo y no tiene todo su stock vencido (sin stock se ve
    // como "sin stock"; con todo vencido, como antes un producto vencido, no se ve).
    public const A_LA_VENTA = 'p.activo = 1 AND (p.stock = 0 OR ' . self::STOCK_VENDIBLE . ' > 0)';

    // El orden de salida: el que vence primero; los que no vencen, al final.
    private const ORDEN = 'l.vencimiento IS NULL, l.vencimiento, l.id';

    public function __construct(private PDO $db)
    {
    }

    // ---------- Leer ----------

    // Los lotes de los productos (o de uno), para el panel: los que tienen stock y los vacíos que no
    // vencieron (se les puede cargar stock). [producto_id => [lotes]].
    public function deProductos(?int $productoId = null): array
    {
        $sql = "SELECT l.id, l.producto_id, l.codigo, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS vencimiento, l.stock,
                       NOT " . self::VENDIBLE . " AS vencido
                FROM lote_producto l
                WHERE (l.stock > 0 OR " . self::VENDIBLE . ')';
        $parametros = [];
        if ($productoId !== null) {
            $sql .= ' AND l.producto_id = ?';
            $parametros[] = $productoId;
        }
        $consulta = $this->db->prepare($sql . ' ORDER BY l.producto_id, ' . self::ORDEN);
        $consulta->execute($parametros);

        $lotes = [];
        foreach ($consulta->fetchAll() as $fila) {
            $lotes[(int) $fila['producto_id']][] = self::formatear($fila);
        }

        return $lotes;
    }

    // Un lote de ese producto, o null.
    public function buscar(int $productoId, int $loteId): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT l.id, l.producto_id, l.codigo, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS vencimiento, l.stock,
                    NOT " . self::VENDIBLE . ' AS vencido
             FROM lote_producto l WHERE l.id = ? AND l.producto_id = ?'
        );
        $consulta->execute([$loteId, $productoId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : self::formatear($fila);
    }

    // El lote con stock que vence primero y se puede vender (para una promoción sin lote elegido), o null.
    public function primeroVendible(int $productoId): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT l.id FROM lote_producto l WHERE l.producto_id = ? AND l.stock > 0 AND ' . self::VENDIBLE
            . ' ORDER BY ' . self::ORDEN . ' LIMIT 1'
        );
        $consulta->execute([$productoId]);
        $id = $consulta->fetchColumn();

        return $id === false ? null : $this->buscar($productoId, (int) $id);
    }

    // ---------- Cambiar stock (dentro de una transacción) ----------

    // Crea un lote vacío y devuelve su id.
    public function crear(int $productoId, ?string $codigo, ?string $vencimiento): int
    {
        $this->db->prepare('INSERT INTO lote_producto (producto_id, codigo, vencimiento, stock, creado_en) VALUES (?, ?, ?, 0, NOW())')
            ->execute([$productoId, $codigo, $vencimiento]);

        return (int) $this->db->lastInsertId();
    }

    // El lote sin vencimiento del producto (el de los accesorios); si no tiene, lo crea. Para cargar
    // stock sin elegir lote.
    public function sinVencimiento(int $productoId): int
    {
        $consulta = $this->db->prepare('SELECT id FROM lote_producto WHERE producto_id = ? AND vencimiento IS NULL ORDER BY id LIMIT 1');
        $consulta->execute([$productoId]);
        $id = $consulta->fetchColumn();

        return $id === false ? $this->crear($productoId, null, null) : (int) $id;
    }

    public function sumar(int $loteId, int $cantidad): void
    {
        $this->db->prepare('UPDATE lote_producto SET stock = stock + ? WHERE id = ?')->execute([$cantidad, $loteId]);
    }

    // Resta de un lote, solo si alcanza. false si no alcanza.
    public function restar(int $loteId, int $cantidad): bool
    {
        $consulta = $this->db->prepare('UPDATE lote_producto SET stock = stock - ? WHERE id = ? AND stock >= ?');
        $consulta->execute([$cantidad, $loteId, $cantidad]);

        return $consulta->rowCount() === 1;
    }

    // Saca $cantidad unidades del producto de los lotes que se pueden vender, empezando por el que
    // vence primero. $primero = [loteId, cantidad]: esas unidades salen antes de ese lote (las de una
    // promoción). Con $conVencidos, también de los vencidos (un retiro manual: primero los vencidos).
    // Bloquea los lotes. Devuelve [[lote_id, cantidad], ...] o null si no alcanza (y no saca nada).
    public function sacar(int $productoId, int $cantidad, ?array $primero = null, bool $conVencidos = false): ?array
    {
        $sql = 'SELECT l.id, l.stock FROM lote_producto l WHERE l.producto_id = ? AND l.stock > 0';
        if (!$conVencidos) {
            $sql .= ' AND ' . self::VENDIBLE;
        }
        $consulta = $this->db->prepare($sql . ' ORDER BY ' . self::ORDEN . ' FOR UPDATE');
        $consulta->execute([$productoId]);
        $disponible = [];
        foreach ($consulta->fetchAll() as $fila) {
            $disponible[(int) $fila['id']] = (int) $fila['stock'];
        }

        $partes = [];
        $falta = $cantidad;
        if ($primero !== null && isset($disponible[$primero[0]])) {
            $tomar = min($falta, $primero[1], $disponible[$primero[0]]);
            if ($tomar > 0) {
                $partes[$primero[0]] = $tomar;
                $disponible[$primero[0]] -= $tomar;
                $falta -= $tomar;
            }
        }
        foreach ($disponible as $loteId => $stock) {
            if ($falta === 0) {
                break;
            }
            $tomar = min($falta, $stock);
            if ($tomar > 0) {
                $partes[$loteId] = ($partes[$loteId] ?? 0) + $tomar;
                $falta -= $tomar;
            }
        }
        if ($falta > 0) {
            return null;
        }

        $resultado = [];
        foreach ($partes as $loteId => $tomar) {
            $this->restar($loteId, $tomar);
            $resultado[] = [$loteId, $tomar];
        }

        return $resultado;
    }

    // Lo que se puede vender hoy de un producto.
    public function stockVendible(int $productoId): int
    {
        $consulta = $this->db->prepare('SELECT COALESCE(SUM(l.stock), 0) FROM lote_producto l WHERE l.producto_id = ? AND ' . self::VENDIBLE);
        $consulta->execute([$productoId]);

        return (int) $consulta->fetchColumn();
    }

    // Vuelve a calcular producto.stock (la suma de los lotes) y producto.fecha_vencimiento (el lote con
    // stock que vence primero). Después de cada cambio de stock de un lote.
    public function sincronizar(int $productoId): void
    {
        $this->db->prepare(
            'UPDATE producto p SET
                 p.stock = (SELECT COALESCE(SUM(l.stock), 0) FROM lote_producto l WHERE l.producto_id = p.id),
                 p.fecha_vencimiento = (SELECT MIN(l.vencimiento) FROM lote_producto l WHERE l.producto_id = p.id AND l.stock > 0)
             WHERE p.id = ?'
        )->execute([$productoId]);
    }

    // ---------- Compras: devolver al lote del que salió ----------

    // Lo que tiene reservado una compra en cada lote, según sus movimientos (ventas y reservas de
    // nuevo menos cancelaciones): [producto_id => [lote_id => cantidad]]. Los movimientos sin lote
    // (anteriores a la migración 028 o de las semillas) van con lote 0.
    public function reservadoPorCompra(string $numero): array
    {
        $consulta = $this->db->prepare(
            "SELECT m.producto_id, COALESCE(m.lote_id, 0) AS lote_id,
                    SUM(IF(m.tipo = 'SALIDA', m.cantidad, -m.cantidad)) AS cantidad
             FROM movimiento_inventario m
             WHERE m.producto_id IS NOT NULL AND m.motivo IN ('VENTA', 'VENTA_CANCELADA')
               AND (m.id_operacion LIKE ? OR m.id_operacion LIKE ? OR m.id_operacion LIKE ?)
             GROUP BY m.producto_id, COALESCE(m.lote_id, 0)"
        );
        $consulta->execute(["%venta-$numero-%", "%revive-$numero-%", "%cancela-$numero-%"]);

        $reservado = [];
        foreach ($consulta->fetchAll() as $fila) {
            if ((int) $fila['cantidad'] > 0) {
                $reservado[(int) $fila['producto_id']][(int) $fila['lote_id']] = (int) $fila['cantidad'];
            }
        }

        return $reservado;
    }

    // Reparte la devolución de $cantidad unidades de un producto entre los lotes de los que salieron
    // ($reservado: [lote_id => cantidad], de reservadoPorCompra). Lo que no se sabe de qué lote salió
    // va al lote más nuevo del producto (o a uno sin vencimiento si no tiene). Devuelve [[lote_id, cantidad], ...].
    public function repartirDevolucion(int $productoId, int $cantidad, array $reservado): array
    {
        $partes = [];
        $falta = $cantidad;
        foreach ($reservado as $loteId => $reservada) {
            if ($loteId === 0 || $falta === 0) {
                continue;
            }
            $tomar = min($falta, $reservada);
            $partes[] = [$loteId, $tomar];
            $falta -= $tomar;
        }
        if ($falta > 0) {
            $consulta = $this->db->prepare('SELECT id FROM lote_producto WHERE producto_id = ? ORDER BY id DESC LIMIT 1');
            $consulta->execute([$productoId]);
            $id = $consulta->fetchColumn();
            $partes[] = [$id === false ? $this->crear($productoId, null, null) : (int) $id, $falta];
        }

        return $partes;
    }

    // ---------- Reglas (sin base) ----------

    // Valida un lote nuevo ({codigo, vencimiento}). El vencimiento es opcional (no vence) y no puede
    // ser anterior a $hoy. Devuelve [datos limpios, errores por campo].
    public static function validarNuevo(mixed $datos, string $hoy): array
    {
        $datos = is_array($datos) ? $datos : [];
        $limpios = [];
        $errores = [];

        $codigo = $datos['codigo'] ?? null;
        if ($codigo !== null && !is_string($codigo)) {
            $errores['codigo'] = 'El número de lote puede tener hasta ' . self::CODIGO_MAXIMO . ' caracteres';
        } else {
            $codigo = $codigo === null ? '' : trim($codigo);
            if (mb_strlen($codigo, 'UTF-8') > self::CODIGO_MAXIMO) {
                $errores['codigo'] = 'El número de lote puede tener hasta ' . self::CODIGO_MAXIMO . ' caracteres';
            } else {
                $limpios['codigo'] = $codigo === '' ? null : $codigo;
            }
        }

        $vencimiento = $datos['vencimiento'] ?? null;
        if ($vencimiento === null || $vencimiento === '') {
            $limpios['vencimiento'] = null;
        } elseif (!is_string($vencimiento) || !self::esFecha($vencimiento)) {
            $errores['vencimiento'] = 'La fecha de vencimiento no es válida';
        } elseif ($vencimiento < $hoy) {
            $errores['vencimiento'] = 'La fecha de vencimiento no puede ser anterior a hoy';
        } else {
            $limpios['vencimiento'] = $vencimiento;
        }

        return [$limpios, $errores];
    }

    // Cómo se nombra un lote en los textos: "lote L-0425 (vence el 16/12/2026)", "lote que vence el
    // 16/12/2026", "lote L-7 (no vence)" o "lote sin vencimiento".
    public static function texto(?string $codigo, ?string $vencimiento): string
    {
        $vence = $vencimiento === null ? null : 'vence el ' . implode('/', array_reverse(explode('-', substr($vencimiento, 0, 10))));
        if ($codigo !== null && $codigo !== '') {
            return "lote $codigo (" . ($vence ?? 'no vence') . ')';
        }

        return $vence === null ? 'lote sin vencimiento' : "lote que $vence";
    }

    // Una fila de lote_producto al formato del contrato.
    public static function formatear(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'codigo' => $fila['codigo'],
            'vencimiento' => $fila['vencimiento'],
            'stock' => (int) $fila['stock'],
            'vencido' => (bool) $fila['vencido'],
        ];
    }

    private static function esFecha(string $valor): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes)) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }
}
