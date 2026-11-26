<?php

// Patrón repositorio: esta es la ÚNICA clase que escribe SQL sobre insumo y sus movimientos
// (movimiento_inventario con insumo_id). Contrato: docs/api/contrato-insumos.md.
// - Todas las consultas son parametrizadas ("?"): los datos van separados del SQL (evita la inyección SQL).
// - Las reglas del insumo (estado según el stock, stock bajo) están en la clase Insumo: acá se
//   usan, no se repiten.
// - Desde la migración 029 el stock está en lotes (clase Lotes, igual que los productos): cargar y
//   retirar cambian un lote, y Lotes::sincronizar() deja en insumo el stock (la suma), el próximo
//   vencimiento y el estado, para que Pacientes, que lee la tabla directo, vea lo correcto.
// - "vence": si el insumo tiene vencimiento. Si vence, cada lote nuevo lleva su fecha; si no, ninguno.
class InsumoRepositorio
{
    private const COLUMNAS = 'i.id, i.nombre, i.aplicacion, i.stock, i.costo, i.estado, i.activo, i.vence, '
        . Lotes::STOCK_SIN_VENCER_INSUMO . ' AS stock_sin_vencer, ' . Lotes::PROXIMO_VENCIMIENTO_INSUMO . ' AS vencimiento';

    // Recibe la conexión de afuera, así las pruebas pueden usar otra base.
    // Hora de Argentina (-03:00) para las fechas de los movimientos y el CURDATE() de los vencimientos.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // Todos los insumos, incluidos los dados de baja, ordenados por nombre.
    // Devuelve una lista de objetos Insumo.
    public function listarTodos(): array
    {
        $consulta = $this->db->query('SELECT ' . self::COLUMNAS . ' FROM insumo i ORDER BY i.nombre, i.id');

        return array_map([Insumo::class, 'desdeFila'], $consulta->fetchAll());
    }

    // Los lotes de todos los insumos (o de uno), para el panel: [insumo_id => [lotes]].
    public function lotes(?int $id = null): array
    {
        return $this->lotesDeInsumos()->listar($id);
    }

    // Un insumo, o null si no existe.
    public function buscarPorId(int $id): ?Insumo
    {
        $consulta = $this->db->prepare('SELECT ' . self::COLUMNAS . ' FROM insumo i WHERE i.id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : Insumo::desdeFila($fila);
    }

    // Crea el insumo y devuelve su id. El estado sale del stock inicial (clase Insumo).
    // Si tiene stock inicial, vencimiento o número de lote, crea su primer lote (L1) y registra el
    // movimiento de entrada. Todo va en una transacción: o se guarda todo, o nada.
    public function insertar(array $datos, int $usuarioId): int
    {
        $nuevo = new Insumo(0, $datos['nombre'], $datos['stock'], $datos['costo'], true, false, $datos['aplicacion'] ?? null);

        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare(
                'INSERT INTO insumo (nombre, aplicacion, stock, costo, estado, vence) VALUES (?, ?, 0, ?, ?, ?)'
            );
            $consulta->execute([
                $nuevo->nombre,
                $nuevo->aplicacion,
                number_format($nuevo->costo, 2, '.', ''),
                $nuevo->estado(),
                $datos['vence'] ? 1 : 0,
            ]);

            $id = (int) $this->db->lastInsertId();

            if ($datos['stock'] > 0 || $datos['vencimiento'] !== null || $datos['lote'] !== null) {
                $lotes = $this->lotesDeInsumos();
                $loteId = $lotes->crear($id, $datos['lote'], $datos['vencimiento']);
                if ($datos['stock'] > 0) {
                    $lotes->sumar($loteId, $datos['stock']);
                    $lotes->movimiento($id, $loteId, 'ENTRADA', 'CARGA_VETERINARIO', $datos['stock'], $usuarioId);
                }
                $lotes->sincronizar($id);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $id;
    }

    // Edita nombre, costo y (si vinieron) la aplicación del calendario y "vence". Nunca el stock, el
    // estado ni activo. InsumoControlador controla que "vence" cambie solo sin stock.
    public function actualizar(int $id, array $datos): void
    {
        $sql = 'UPDATE insumo SET nombre = ?, costo = ?';
        $parametros = [$datos['nombre'], number_format($datos['costo'], 2, '.', '')];
        if (array_key_exists('aplicacion', $datos)) {
            $sql .= ', aplicacion = ?';
            $parametros[] = $datos['aplicacion'];
        }
        if (array_key_exists('vence', $datos)) {
            $sql .= ', vence = ?';
            $parametros[] = $datos['vence'] ? 1 : 0;
        }
        $parametros[] = $id;

        $this->db->prepare($sql . ' WHERE id = ?')->execute($parametros);
    }

    // Baja lógica: el insumo queda inactivo y conserva su historial. Nunca se borra la fila.
    public function darDeBaja(int $id): void
    {
        $consulta = $this->db->prepare('UPDATE insumo SET activo = 0 WHERE id = ?');
        $consulta->execute([$id]);
    }

    // Reactivar: deshace la baja lógica. El insumo vuelve con el stock y el historial que tenía.
    public function reactivar(int $id): void
    {
        $consulta = $this->db->prepare('UPDATE insumo SET activo = 1 WHERE id = ?');
        $consulta->execute([$id]);
    }

    // Marca ($pedido = true) o desmarca "Ya pedido". Devuelve el insumo como quedó, o null si no
    // existe o está dado de baja. Si se pide marcar y no tiene stock bajo, vuelve sin cambios.
    public function marcarPedido(int $id, bool $pedido): ?Insumo
    {
        $this->db->beginTransaction();
        try {
            $insumo = $this->bloquear($id);
            if ($insumo === null || !$insumo->activo) {
                $this->db->rollBack();
                return null;
            }

            if ($pedido) {
                $insumo->marcarPedido();
            } else {
                $insumo->pedido = false;
            }
            $this->db->prepare('UPDATE insumo SET estado = ? WHERE id = ?')->execute([$insumo->estado(), $id]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $insumo;
    }

    // Carga de stock (UML: reponer) en un lote: $loteId (que ya existe), uno nuevo ($nuevo: {codigo,
    // vencimiento}, ya validado) o, si no viene ninguno y el insumo no vence, su lote sin vencimiento
    // (Lotes::paraCargar). Registra la ENTRADA. Llegó el pedido: deja de estar "Ya pedido".
    // Devuelve [el insumo actualizado, el lote], 'baja' (no existe o está dado de baja) o el motivo
    // de Lotes::paraCargar.
    public function cargarStock(int $id, int $cantidad, int $usuarioId, ?int $loteId = null, ?array $nuevo = null): array|string
    {
        $this->db->beginTransaction();
        try {
            $lotes = $this->lotesDeInsumos();
            $actual = $lotes->bloquear($id);
            if ($actual === null || !$actual['activo']) {
                $this->db->rollBack();
                return 'baja';
            }

            $loteId = $lotes->paraCargar($id, $actual['vence'], $loteId, $nuevo);
            if (is_string($loteId)) {
                $this->db->rollBack();
                return $loteId;
            }

            $lotes->sumar($loteId, $cantidad);
            $lotes->movimiento($id, $loteId, 'ENTRADA', 'CARGA_VETERINARIO', $cantidad, $usuarioId);
            $this->db->prepare("UPDATE insumo SET estado = 'DISPONIBLE' WHERE id = ?")->execute([$id]);
            $lotes->sincronizar($id);
            $lote = $lotes->buscar($id, $loteId);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return [$this->buscarPorId($id), $lote];
    }

    // Retiro manual, sin consulta (UML: descontarStock): de $loteId o, si no viene, de los lotes que
    // vencen primero (los vencidos antes que nada). Registra la SALIDA con motivo AJUSTE (en la
    // pantalla: "Retiro manual (sin consulta)"). Devuelve el insumo actualizado, 'baja',
    // 'lote_inexistente' o ['insuficiente', lo que hay].
    public function retirarStock(int $id, int $cantidad, int $usuarioId, ?int $loteId = null): Insumo|string|array
    {
        $this->db->beginTransaction();
        try {
            $lotes = $this->lotesDeInsumos();
            $actual = $lotes->bloquear($id);
            if ($actual === null || !$actual['activo']) {
                $this->db->rollBack();
                return 'baja';
            }

            $partes = $lotes->retirar($id, $cantidad, $loteId, $actual['stock']);
            if (is_string($partes) || $partes[0] === 'insuficiente') {
                $this->db->rollBack();
                return $partes;
            }

            foreach ($partes as [$deLote, $tomadas]) {
                $lotes->movimiento($id, $deLote, 'SALIDA', 'AJUSTE', $tomadas, $usuarioId);
            }
            $lotes->sincronizar($id);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $this->buscarPorId($id);
    }

    // Corrige el número del fabricante o el vencimiento de un lote. $datos: {codigo, vencimiento}, ya
    // validados. false si el lote no es de ese insumo.
    public function editarLote(int $id, int $loteId, array $datos): bool
    {
        $this->db->beginTransaction();
        try {
            $lotes = $this->lotesDeInsumos();
            $existe = $lotes->editar($id, $loteId, $datos);
            $lotes->sincronizar($id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $existe;
    }

    // Los movimientos de los insumos (o de uno), del más reciente al más antiguo. $desde y $hasta
    // (AAAA-MM-DD, inclusive) filtran por el día del movimiento. Cada uno trae el nombre de quien lo
    // registró, de qué lote (029) y de dónde salió (consulta_id o compra, ReferenciaMovimiento).
    public function movimientos(?int $insumoId = null, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT m.id, DATE_FORMAT(m.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, m.tipo, m.motivo, m.cantidad,
                       m.insumo_id, i.nombre AS insumo, u.nombre AS usuario, m.id_operacion,
                       l.id AS lote_id, l.numero AS lote_numero, l.codigo AS lote_codigo,
                       DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS lote_vencimiento
                FROM movimiento_inventario m
                JOIN insumo i ON i.id = m.insumo_id
                LEFT JOIN lote_insumo l ON l.id = m.lote_insumo_id
                LEFT JOIN usuario u ON u.id = m.usuario_id
                WHERE 1 = 1";
        $parametros = [];

        if ($insumoId !== null) {
            $sql .= ' AND m.insumo_id = ?';
            $parametros[] = $insumoId;
        }
        if ($desde !== null) {
            $sql .= ' AND m.fecha >= ?';
            $parametros[] = $desde . ' 00:00:00';
        }
        if ($hasta !== null) {
            $sql .= ' AND m.fecha < ? + INTERVAL 1 DAY';
            $parametros[] = $hasta;
        }

        $consulta = $this->db->prepare($sql . ' ORDER BY m.fecha DESC, m.id DESC');
        $consulta->execute($parametros);

        return array_map(fn (array $fila) => [
            'id' => (int) $fila['id'],
            'fecha' => $fila['fecha'],
            'tipo' => $fila['tipo'],
            'motivo' => $fila['motivo'],
            'cantidad' => (int) $fila['cantidad'],
            'insumo_id' => (int) $fila['insumo_id'],
            'insumo' => $fila['insumo'],
            'usuario' => $fila['usuario'],
            // De qué lote (029): {id, numero, nombre, codigo, vencimiento}, o null en los de antes.
            'lote' => $fila['lote_id'] === null ? null : [
                'id' => (int) $fila['lote_id'],
                'numero' => (int) $fila['lote_numero'],
                'nombre' => Lotes::nombre((int) $fila['lote_numero'], $fila['lote_codigo']),
                'codigo' => $fila['lote_codigo'],
                'vencimiento' => $fila['lote_vencimiento'],
            ],
        ] + ReferenciaMovimiento::de($fila['id_operacion']), $consulta->fetchAll());
    }

    // Lee el insumo y BLOQUEA su fila hasta el final de la transacción (FOR UPDATE).
    // Si dos personas lo cambian al mismo tiempo, la segunda espera a que termine la primera,
    // así ninguna trabaja con datos viejos. Se llama siempre dentro de una transacción.
    private function bloquear(int $id): ?Insumo
    {
        $consulta = $this->db->prepare('SELECT ' . self::COLUMNAS . ' FROM insumo i WHERE i.id = ? FOR UPDATE');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : Insumo::desdeFila($fila);
    }

    private function lotesDeInsumos(): Lotes
    {
        return Lotes::deInsumos($this->db);
    }
}
