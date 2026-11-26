<?php

// Los lotes de los productos (tabla lote_producto, migración 028) y de los insumos (lote_insumo, 029).
// Contrato: docs/api/contrato.md y contrato-insumos.md, sección "Lotes". Es la ÚNICA clase que cambia
// el stock de un lote. new Lotes($db) trabaja con los de productos; Lotes::deInsumos($db), con los de
// insumos: las reglas son las mismas.
// - Cada lote tiene su número (1, 2, 3…: "L1", "L2", "L3" en la pantalla, el que sigue del producto o
//   insumo), su número de lote del fabricante (codigo, opcional), su vencimiento (o ninguno) y su stock.
// - Si el producto o insumo vence (columna vence, 029), cada lote nuevo lleva su vencimiento; si no
//   vence, ninguno lo lleva.
// - producto.stock (insumo.stock) es la suma de los lotes y fecha_vencimiento, el del lote con stock
//   que vence primero: los actualiza sincronizar(), en la misma transacción que cambia el lote.
// - Se vende y se usa en las consultas lo que NO está vencido, empezando por el lote que vence primero
//   (los que no vencen, al final).
// - Un lote vencido con stock queda en el panel (y en Reportes) para retirarlo; no se vende ni se usa.
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

    // Lo mismo para las consultas que traen insumos "i" (029): lo que se puede usar hoy y el próximo vencimiento.
    public const STOCK_SIN_VENCER_INSUMO = '(SELECT COALESCE(SUM(l.stock), 0) FROM lote_insumo l
                                             WHERE l.insumo_id = i.id AND (l.vencimiento IS NULL OR l.vencimiento >= CURDATE()))';
    public const PROXIMO_VENCIMIENTO_INSUMO = "(SELECT DATE_FORMAT(MIN(l.vencimiento), '%Y-%m-%d') FROM lote_insumo l
                                                WHERE l.insumo_id = i.id AND l.stock > 0 AND l.vencimiento >= CURDATE())";

    // Un producto está a la venta si está activo y no tiene todo su stock vencido (sin stock se ve
    // como "sin stock"; con todo vencido, como antes un producto vencido, no se ve).
    public const A_LA_VENTA = 'p.activo = 1 AND (p.stock = 0 OR ' . self::STOCK_VENDIBLE . ' > 0)';

    // El orden de salida: el que vence primero; los que no vencen, al final.
    private const ORDEN = 'l.vencimiento IS NULL, l.vencimiento, l.id';

    // Las tablas de cada uno: [tabla de lotes, columna del producto o insumo, tabla del producto o
    // insumo, columna del lote en movimiento_inventario]. Fijas: nunca salen del pedido.
    private const TABLAS = [
        'producto' => ['lote_producto', 'producto_id', 'producto', 'lote_id'],
        'insumo' => ['lote_insumo', 'insumo_id', 'insumo', 'lote_insumo_id'],
    ];

    private string $tabla;
    private string $columna;
    private string $padre;
    private string $columnaMovimiento;

    public function __construct(private PDO $db, private string $de = 'producto')
    {
        [$this->tabla, $this->columna, $this->padre, $this->columnaMovimiento] = self::TABLAS[$de];
    }

    // Los lotes de los insumos.
    public static function deInsumos(PDO $db): self
    {
        return new self($db, 'insumo');
    }

    // ---------- Leer ----------

    // Los lotes de todos los productos o insumos (o de uno), para el panel: los que tienen stock y los
    // vacíos que no vencieron y siguen la regla de "vence" (se les puede cargar stock).
    // [id del producto o insumo => [lotes]].
    public function listar(?int $id = null): array
    {
        $sql = "SELECT l.id, l.{$this->columna} AS item_id, l.numero, l.codigo, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS vencimiento,
                       l.stock, NOT " . self::VENDIBLE . " AS vencido
                FROM {$this->tabla} l JOIN {$this->padre} x ON x.id = l.{$this->columna}
                WHERE (l.stock > 0 OR (" . self::VENDIBLE . ' AND (l.vencimiento IS NOT NULL) = x.vence))';
        $parametros = [];
        if ($id !== null) {
            $sql .= " AND l.{$this->columna} = ?";
            $parametros[] = $id;
        }
        $consulta = $this->db->prepare($sql . " ORDER BY l.{$this->columna}, " . self::ORDEN);
        $consulta->execute($parametros);

        $lotes = [];
        foreach ($consulta->fetchAll() as $fila) {
            $lotes[(int) $fila['item_id']][] = self::formatear($fila);
        }

        return $lotes;
    }

    // Un lote de ese producto o insumo, o null.
    public function buscar(int $id, int $loteId): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT l.id, l.numero, l.codigo, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS vencimiento, l.stock,
                    NOT " . self::VENDIBLE . " AS vencido
             FROM {$this->tabla} l WHERE l.id = ? AND l.{$this->columna} = ?"
        );
        $consulta->execute([$loteId, $id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : self::formatear($fila);
    }

    // El lote con stock que vence primero y se puede vender (para una promoción sin lote elegido), o null.
    public function primeroVendible(int $id): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT l.id FROM {$this->tabla} l WHERE l.{$this->columna} = ? AND l.stock > 0 AND " . self::VENDIBLE
            . ' ORDER BY ' . self::ORDEN . ' LIMIT 1'
        );
        $consulta->execute([$id]);
        $loteId = $consulta->fetchColumn();

        return $loteId === false ? null : $this->buscar($id, (int) $loteId);
    }

    // Lo que se puede vender o usar hoy.
    public function stockVendible(int $id): int
    {
        $consulta = $this->db->prepare("SELECT COALESCE(SUM(l.stock), 0) FROM {$this->tabla} l WHERE l.{$this->columna} = ? AND " . self::VENDIBLE);
        $consulta->execute([$id]);

        return (int) $consulta->fetchColumn();
    }

    // ---------- Cambiar stock (dentro de una transacción) ----------

    // Lee el producto o insumo y BLOQUEA su fila hasta el final de la transacción: {activo, vence,
    // stock}, o null si no existe. Dos cargas o retiros al mismo tiempo esperan uno al otro, y el
    // número del lote nuevo no se repite.
    public function bloquear(int $id): ?array
    {
        $consulta = $this->db->prepare("SELECT activo, vence, stock FROM {$this->padre} WHERE id = ? FOR UPDATE");
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : [
            'activo' => (bool) $fila['activo'],
            'vence' => (bool) $fila['vence'],
            'stock' => (int) $fila['stock'],
        ];
    }

    // Crea un lote vacío, con el número que sigue del producto o insumo, y devuelve su id.
    public function crear(int $id, ?string $codigo, ?string $vencimiento): int
    {
        $this->db->prepare(
            "INSERT INTO {$this->tabla} ({$this->columna}, numero, codigo, vencimiento, stock, creado_en)
             SELECT ?, COALESCE(MAX(numero), 0) + 1, ?, ?, 0, NOW() FROM {$this->tabla} WHERE {$this->columna} = ?"
        )->execute([$id, $codigo, $vencimiento, $id]);

        return (int) $this->db->lastInsertId();
    }

    // El lote sin vencimiento (el de lo que no vence); si no tiene, lo crea. Para cargar stock sin
    // elegir lote.
    public function sinVencimiento(int $id): int
    {
        $consulta = $this->db->prepare("SELECT id FROM {$this->tabla} WHERE {$this->columna} = ? AND vencimiento IS NULL ORDER BY id LIMIT 1");
        $consulta->execute([$id]);
        $loteId = $consulta->fetchColumn();

        return $loteId === false ? $this->crear($id, null, null) : (int) $loteId;
    }

    // En qué lote se carga stock: $loteId (de ese producto o insumo, sin vencer y que siga la regla de
    // "vence"), uno nuevo ($nuevo: {codigo, vencimiento}, ya validado con validarNuevo) o, si no viene
    // ninguno y no vence, el lote sin vencimiento. Devuelve el id del lote o el motivo por el que no:
    // 'lote_inexistente', 'lote_vencido', 'lote_no_corresponde' (tiene vencimiento y no vence, o al
    // revés) o 'falta_lote' (vence y no se eligió lote).
    public function paraCargar(int $id, bool $vence, ?int $loteId, ?array $nuevo): int|string
    {
        if ($loteId !== null) {
            $lote = $this->buscar($id, $loteId);
            if ($lote === null) {
                return 'lote_inexistente';
            }
            if ($lote['vencido']) {
                return 'lote_vencido';
            }
            if (($lote['vencimiento'] !== null) !== $vence) {
                return 'lote_no_corresponde';
            }

            return $loteId;
        }
        if ($nuevo !== null) {
            return $this->crear($id, $nuevo['codigo'], $nuevo['vencimiento']);
        }

        return $vence ? 'falta_lote' : $this->sinVencimiento($id);
    }

    public function sumar(int $loteId, int $cantidad): void
    {
        $this->db->prepare("UPDATE {$this->tabla} SET stock = stock + ? WHERE id = ?")->execute([$cantidad, $loteId]);
    }

    // Resta de un lote, solo si alcanza. false si no alcanza.
    public function restar(int $loteId, int $cantidad): bool
    {
        $consulta = $this->db->prepare("UPDATE {$this->tabla} SET stock = stock - ? WHERE id = ? AND stock >= ?");
        $consulta->execute([$cantidad, $loteId, $cantidad]);

        return $consulta->rowCount() === 1;
    }

    // Saca $cantidad unidades de los lotes que se pueden vender o usar, empezando por el que vence
    // primero. $primero = [loteId, cantidad]: esas unidades salen antes de ese lote (las de una
    // promoción). Con $conVencidos, también de los vencidos (un retiro manual: primero los vencidos).
    // Bloquea los lotes. Devuelve [[lote_id, cantidad], ...] o null si no alcanza (y no saca nada).
    public function sacar(int $id, int $cantidad, ?array $primero = null, bool $conVencidos = false): ?array
    {
        $sql = "SELECT l.id, l.stock FROM {$this->tabla} l WHERE l.{$this->columna} = ? AND l.stock > 0";
        if (!$conVencidos) {
            $sql .= ' AND ' . self::VENDIBLE;
        }
        $consulta = $this->db->prepare($sql . ' ORDER BY ' . self::ORDEN . ' FOR UPDATE');
        $consulta->execute([$id]);
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

    // Un retiro manual: de $loteId o, si no viene, de los lotes que vencen primero (los vencidos antes
    // que nada). Devuelve [[lote_id, cantidad], ...], 'lote_inexistente' o ['insuficiente', lo que hay].
    public function retirar(int $id, int $cantidad, ?int $loteId, int $stockTotal): array|string
    {
        if ($loteId === null) {
            return $this->sacar($id, $cantidad, null, true) ?? ['insuficiente', $stockTotal];
        }
        $lote = $this->buscar($id, $loteId);
        if ($lote === null) {
            return 'lote_inexistente';
        }

        return $this->restar($loteId, $cantidad) ? [[$loteId, $cantidad]] : ['insuficiente', $lote['stock']];
    }

    // Corrige el número del fabricante o el vencimiento de un lote ($datos ya validados con
    // validarNuevo). false si el lote no es de ese producto o insumo.
    public function editar(int $id, int $loteId, array $datos): bool
    {
        if ($this->buscar($id, $loteId) === null) {
            return false;
        }
        $this->db->prepare("UPDATE {$this->tabla} SET codigo = ?, vencimiento = ? WHERE id = ? AND {$this->columna} = ?")
            ->execute([$datos['codigo'], $datos['vencimiento'], $loteId, $id]);

        return true;
    }

    // Un movimiento de stock de un lote. $idOperacion: null en las cargas y retiros manuales.
    public function movimiento(int $id, int $loteId, string $tipo, string $motivo, int $cantidad, ?int $usuarioId, ?string $idOperacion = null): void
    {
        $this->db->prepare(
            "INSERT INTO movimiento_inventario ({$this->columna}, {$this->columnaMovimiento}, tipo, cantidad, motivo, usuario_id, id_operacion)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$id, $loteId, $tipo, $cantidad, $motivo, $usuarioId, $idOperacion]);
    }

    // Vuelve a calcular el stock (la suma de los lotes) y fecha_vencimiento (el lote con stock que
    // vence primero) del producto o insumo. Después de cada cambio de stock de un lote. De un insumo,
    // también el estado (sin stock: EN_FALTA; "Ya pedido" queda hasta que se carga stock).
    public function sincronizar(int $id): void
    {
        $estado = $this->de === 'insumo'
            ? ", x.estado = IF(x.estado = 'YA_PEDIDO', 'YA_PEDIDO', IF(x.stock = 0, 'EN_FALTA', 'DISPONIBLE'))"
            : '';
        // MySQL aplica el SET de izquierda a derecha: el estado ya ve el stock nuevo.
        $this->db->prepare(
            "UPDATE {$this->padre} x SET
                 x.stock = (SELECT COALESCE(SUM(l.stock), 0) FROM {$this->tabla} l WHERE l.{$this->columna} = x.id),
                 x.fecha_vencimiento = (SELECT MIN(l.vencimiento) FROM {$this->tabla} l WHERE l.{$this->columna} = x.id AND l.stock > 0)
                 $estado
             WHERE x.id = ?"
        )->execute([$id]);
    }

    // ---------- Compras: devolver al lote del que salió (solo productos) ----------

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
            $consulta = $this->db->prepare("SELECT id FROM {$this->tabla} WHERE {$this->columna} = ? ORDER BY id DESC LIMIT 1");
            $consulta->execute([$productoId]);
            $id = $consulta->fetchColumn();
            $partes[] = [$id === false ? $this->crear($productoId, null, null) : (int) $id, $falta];
        }

        return $partes;
    }

    // ---------- Reglas (sin base) ----------

    // Valida un lote nuevo ({codigo, vencimiento}). codigo es el número de lote del fabricante
    // (opcional). El vencimiento no puede ser anterior a $hoy. Con $vence (029): true exige
    // vencimiento y false no lo acepta; null no controla eso. $que nombra lo que vence en los
    // mensajes ("producto" o "insumo"). Devuelve [datos limpios, errores por campo].
    public static function validarNuevo(mixed $datos, string $hoy, ?bool $vence = null, string $que = 'producto'): array
    {
        $datos = is_array($datos) ? $datos : [];
        $limpios = [];
        $errores = [];

        $codigo = $datos['codigo'] ?? null;
        if ($codigo !== null && !is_string($codigo)) {
            $errores['codigo'] = 'El número de lote del fabricante puede tener hasta ' . self::CODIGO_MAXIMO . ' caracteres';
        } else {
            $codigo = $codigo === null ? '' : trim($codigo);
            if (mb_strlen($codigo, 'UTF-8') > self::CODIGO_MAXIMO) {
                $errores['codigo'] = 'El número de lote del fabricante puede tener hasta ' . self::CODIGO_MAXIMO . ' caracteres';
            } else {
                $limpios['codigo'] = $codigo === '' ? null : $codigo;
            }
        }

        $vencimiento = $datos['vencimiento'] ?? null;
        if ($vencimiento === null || $vencimiento === '') {
            if ($vence === true) {
                $errores['vencimiento'] = "Este $que vence: poné la fecha de vencimiento del lote";
            } else {
                $limpios['vencimiento'] = null;
            }
        } elseif ($vence === false) {
            $errores['vencimiento'] = "Este $que no vence: dejá el vencimiento vacío";
        } elseif (!is_string($vencimiento) || !self::esFecha($vencimiento)) {
            $errores['vencimiento'] = 'La fecha de vencimiento no es válida';
        } elseif ($vencimiento < $hoy) {
            $errores['vencimiento'] = 'La fecha de vencimiento no puede ser anterior a hoy';
        } else {
            $limpios['vencimiento'] = $vencimiento;
        }

        return [$limpios, $errores];
    }

    // "vence" del alta o la edición: true o false. Devuelve [valor, error] (error null si está bien).
    // En el alta es obligatorio; en la edición, si no viene, no se cambia (valor null).
    public static function validarVence(array $datos, bool $obligatorio): array
    {
        if (!array_key_exists('vence', $datos) || $datos['vence'] === null) {
            return [null, $obligatorio ? 'Indicá si vence o no' : null];
        }

        return is_bool($datos['vence']) ? [$datos['vence'], null] : [null, 'Indicá si vence o no'];
    }

    // Cómo se nombra un lote: "L3" o, con el número del fabricante, "L3 (FL-2612)". Sin número (un
    // lote creado antes de la migración 029 por el Inventario anterior): el del fabricante o "sin número".
    public static function nombre(?int $numero, ?string $codigo): string
    {
        $fabricante = $codigo !== null && $codigo !== '' ? $codigo : null;
        if ($numero === null) {
            return $fabricante ?? 'sin número';
        }

        return 'L' . $numero . ($fabricante !== null ? " ($fabricante)" : '');
    }

    // Una fila de lote_producto o lote_insumo al formato del contrato.
    public static function formatear(array $fila): array
    {
        return [
            'id' => (int) $fila['id'],
            'numero' => $fila['numero'] === null ? null : (int) $fila['numero'],
            'nombre' => self::nombre($fila['numero'] === null ? null : (int) $fila['numero'], $fila['codigo']),
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
