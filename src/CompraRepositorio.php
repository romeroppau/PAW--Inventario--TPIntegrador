<?php

// El SQL de las compras de la tienda (factura_compra, y el carrito y el stock que mueven).
// Contrato: docs/api/contrato-tienda.md. Las reglas de plazos y avisos están en la clase Compra.
// - Todas las consultas son parametrizadas ("?").
// - Confirmar y cancelar mueven el stock: van en una transacción y bloquean las filas que tocan
//   (SELECT ... FOR UPDATE), así dos pedidos al mismo tiempo no se pisan.
class CompraRepositorio
{
    private const A_LA_VENTA = 'p.activo = 1 AND (p.fecha_vencimiento IS NULL OR p.fecha_vencimiento >= CURDATE())';

    // Hora de Argentina (-03:00): la fecha de la factura y de los movimientos.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // ---------- Confirmar ----------

    // Confirma el carrito abierto del comprador, en una sola transacción:
    // controla el stock, lo reserva (SALIDA / VENTA), crea la factura V-… y cierra el carrito.
    // Devuelve el número de la factura, 'vacio', o ['stock_insuficiente', [problemas]].
    public function confirmar(int $compradorId): string|array
    {
        $this->db->beginTransaction();
        try {
            // Se bloquea el carrito: un doble clic en "Confirmar" espera al primero y después ya no
            // encuentra un carrito abierto.
            $consulta = $this->db->prepare("SELECT id FROM carrito WHERE cliente_id = ? AND estado = 'ABIERTO' FOR UPDATE");
            $consulta->execute([$compradorId]);
            $carritoId = $consulta->fetchColumn();

            // Los ítems con su producto, bloqueando los productos (en orden de id, para no trabarse
            // con otra compra que bloquee los mismos productos).
            $items = $carritoId === false ? [] : $this->itemsParaConfirmar((int) $carritoId);
            if ($items === []) {
                $this->db->rollBack();
                return 'vacio';
            }

            $problemas = [];
            $total = 0.0;
            foreach ($items as $item) {
                if (!$item['a_la_venta'] || $item['cantidad'] > $item['stock']) {
                    $problemas[] = [
                        'producto_id' => $item['producto_id'],
                        'nombre' => $item['nombre'],
                        'pedido' => $item['cantidad'],
                        'stock' => $item['a_la_venta'] ? $item['stock'] : 0,
                    ];
                }
                $total += round($item['precio_unitario'] * $item['cantidad'], 2);
            }
            if ($problemas !== []) {
                $this->db->rollBack();
                return ['stock_insuficiente', $problemas];
            }

            // La factura. El número sale del id, así que primero se inserta con uno provisorio.
            $consulta = $this->db->prepare(
                "INSERT INTO factura_compra (numero, carrito_id, total, estado, fecha) VALUES (?, ?, ?, 'PENDIENTE_PAGO', NOW())"
            );
            $consulta->execute(['V-TMP-' . $carritoId, $carritoId, number_format($total, 2, '.', '')]);
            $facturaId = (int) $this->db->lastInsertId();
            $numero = Compra::numero($facturaId);
            $this->db->prepare('UPDATE factura_compra SET numero = ? WHERE id = ?')->execute([$numero, $facturaId]);

            // La reserva del stock, con su movimiento (un id_operacion por producto: nunca se repite).
            foreach ($items as $item) {
                $this->db->prepare('UPDATE producto SET stock = stock - ? WHERE id = ?')
                    ->execute([$item['cantidad'], $item['producto_id']]);
                $this->registrarMovimiento($item['producto_id'], 'SALIDA', 'VENTA', $item['cantidad'], $compradorId, "venta-$numero-{$item['producto_id']}");
            }

            $this->db->prepare("UPDATE carrito SET estado = 'CONFIRMADO' WHERE id = ?")->execute([$carritoId]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $numero;
    }

    // ---------- Consultar ----------

    // Las compras de un comprador, de la más nueva a la más vieja.
    public function delComprador(int $compradorId): array
    {
        $consulta = $this->db->prepare(
            "SELECT f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, f.total
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE c.cliente_id = ?
             ORDER BY f.fecha DESC, f.id DESC"
        );
        $consulta->execute([$compradorId]);

        return array_map([$this, 'resumen'], $consulta->fetchAll());
    }

    // Una compra con sus productos. Con $compradorId, solo si es de ese comprador (si no, null).
    public function detalle(string $numero, ?int $compradorId = null): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, f.total,
                    DATE_FORMAT(f.entregada_en, '%Y-%m-%d %H:%i:%s') AS entregada_en, c.cliente_id
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE f.numero = ?"
        );
        $consulta->execute([$numero]);
        $fila = $consulta->fetch();

        if ($fila === false || ($compradorId !== null && (int) $fila['cliente_id'] !== $compradorId)) {
            return null;
        }

        $compra = $this->resumen($fila);
        $compra['entregada_en'] = $fila['entregada_en'];
        $compra['items'] = $this->itemsDeFactura((int) $fila['id']);

        return $compra;
    }

    // ---------- Veterinaria ----------

    // Pedidos de una pestaña de la veterinaria (PAGADA, ENTREGADA o CANCELADA), con el comprador y
    // sus productos. Con $buscar, solo los que coinciden por número de pedido o nombre del comprador.
    // Para retirar: del que vence antes al que vence después (el plazo sale de la fecha).
    // Entregados y cancelados (historial): los más recientes primero.
    public function deLaVeterinaria(string $estado, string $buscar = ''): array
    {
        $orden = match ($estado) {
            'PAGADA' => 'f.fecha, f.id',
            'ENTREGADA' => 'f.entregada_en DESC, f.id DESC',
            default => 'f.fecha DESC, f.id DESC',
        };

        $sql = "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, f.total,
                       u.nombre AS comprador,
                       DATE_FORMAT(f.entregada_en, '%Y-%m-%d %H:%i:%s') AS entregada_en, v.nombre AS entregada_por
                FROM factura_compra f
                JOIN carrito c ON c.id = f.carrito_id
                JOIN usuario u ON u.id = c.cliente_id
                LEFT JOIN usuario v ON v.id = f.entregada_por
                WHERE f.estado = ?";
        $parametros = [$estado];

        if ($buscar !== '') {
            // El número se busca por los dígitos del final: "101", "V-101" y "V-000101" encuentran V-000101.
            // El nombre, por cualquier parte. Se escapan % y _ para que se busquen como letras.
            $digitos = ltrim(preg_replace('/\D/', '', $buscar), '0');
            $condiciones = ["u.nombre LIKE ?"];
            $parametros[] = '%' . addcslashes($buscar, '%_\\') . '%';
            if ($digitos !== '') {
                $condiciones[] = "f.numero LIKE ?";
                $parametros[] = '%' . $digitos;
            }
            $sql .= ' AND (' . implode(' OR ', $condiciones) . ')';
        }

        $consulta = $this->db->prepare("$sql ORDER BY $orden");
        $consulta->execute($parametros);

        return array_map(function (array $fila) use ($estado) {
            $compra = $this->resumen($fila);
            $compra['comprador'] = $fila['comprador'];
            if ($estado === 'ENTREGADA') {
                $compra['entregada_en'] = $fila['entregada_en'];
                $compra['entregada_por'] = $fila['entregada_por'];
            }
            $compra['items'] = array_map(
                fn (array $item) => ['nombre' => $item['nombre'], 'cantidad' => $item['cantidad']],
                $this->itemsDeFactura((int) $fila['id'])
            );
            return $compra;
        }, $consulta->fetchAll());
    }

    // La veterinaria constata el retiro en el local: PAGADA → ENTREGADA (con cuándo y quién),
    // y el carrito a RETIRADO. Devuelve 'ok', 'no_existe' o 'no_lista'.
    public function marcarEntregada(string $numero, int $veterinariaId): string
    {
        $this->db->beginTransaction();
        try {
            $factura = $this->bloquearFactura($numero);
            if ($factura === null) {
                $this->db->rollBack();
                return 'no_existe';
            }
            if ($factura['estado'] !== 'PAGADA') {
                $this->db->rollBack();
                return 'no_lista';
            }

            $this->db->prepare("UPDATE factura_compra SET estado = 'ENTREGADA', entregada_en = NOW(), entregada_por = ? WHERE id = ?")
                ->execute([$veterinariaId, $factura['id']]);
            $this->db->prepare("UPDATE carrito SET estado = 'RETIRADO' WHERE id = ?")->execute([$factura['carrito_id']]);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return 'ok';
    }

    // ---------- Cancelar ----------

    // Arrepentimiento: el comprador cancela una compra que todavía no pagó. El stock vuelve.
    // Devuelve 'ok', 'no_existe', 'pagada' o 'cancelada'.
    public function cancelarSinPagar(string $numero, int $compradorId): string
    {
        $this->db->beginTransaction();
        try {
            $factura = $this->bloquearFactura($numero);
            if ($factura === null || (int) $factura['cliente_id'] !== $compradorId) {
                $this->db->rollBack();
                return 'no_existe';
            }
            if ($factura['estado'] === 'CANCELADA') {
                $this->db->rollBack();
                return 'cancelada';
            }
            if ($factura['estado'] !== 'PENDIENTE_PAGO') {
                $this->db->rollBack();
                return 'pagada';
            }

            $this->cancelar($factura, $compradorId);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return 'ok';
    }

    // ---------- Vencimientos ----------
    // Los usa la clase Vencimientos (la tarea programada y la cancelación "al pasar").

    // Los números de las compras vencidas: sin pagar con más de 15 minutos, o pagadas que pasaron
    // el plazo de retiro. El SQL hace un primer filtro amplio y la regla exacta la decide Compra.
    public function vencidas(DateTimeImmutable $ahora): array
    {
        $consulta = $this->db->prepare(
            "SELECT numero, estado, DATE_FORMAT(fecha, '%Y-%m-%d %H:%i:%s') AS fecha
             FROM factura_compra
             WHERE (estado = 'PENDIENTE_PAGO' AND fecha <= ?) OR (estado = 'PAGADA' AND fecha <= ?)"
        );
        $consulta->execute([
            $ahora->modify('-' . Compra::MINUTOS_PARA_PAGAR . ' minutes')->format('Y-m-d H:i:s'),
            // 5 días hábiles son siempre más de 5 días de calendario.
            $ahora->modify('-' . Compra::DIAS_HABILES_PARA_RETIRAR . ' days')->format('Y-m-d H:i:s'),
        ]);

        $numeros = [];
        foreach ($consulta->fetchAll() as $fila) {
            if (self::motivoDeVencimiento($fila, $ahora) !== null) {
                $numeros[] = $fila['numero'];
            }
        }

        return $numeros;
    }

    // Cancela una compra vencida (el stock vuelve; nadie la cancela: usuario null).
    // Antes la bloquea y vuelve a mirar el estado: si mientras tanto se pagó, se entregó o ya se
    // canceló, no hace nada. Devuelve 'sin_pago', 'sin_retirar' o null si no la canceló.
    public function cancelarVencida(string $numero, DateTimeImmutable $ahora): ?string
    {
        $this->db->beginTransaction();
        try {
            $factura = $this->bloquearFactura($numero);
            $motivo = $factura === null ? null : self::motivoDeVencimiento($factura, $ahora);

            if ($motivo === null) {
                $this->db->rollBack();
                return null;
            }

            $this->cancelar($factura, null);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $motivo;
    }

    // Las compras pagadas, con el comprador: para ver a cuáles les toca recordatorio hoy.
    public function pagadasConComprador(): array
    {
        return $this->db->query(
            "SELECT f.numero, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, c.cliente_id, u.nombre
             FROM factura_compra f
             JOIN carrito c ON c.id = f.carrito_id
             JOIN usuario u ON u.id = c.cliente_id
             WHERE f.estado = 'PAGADA'"
        )->fetchAll();
    }

    // Las compras pagadas que se cancelaron (solo pueden haberse cancelado por vencer el plazo de
    // retiro) en los últimos días, con el comprador: para el aviso de cancelación.
    public function canceladasPagadasConComprador(DateTimeImmutable $ahora): array
    {
        $consulta = $this->db->prepare(
            "SELECT f.numero, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, c.cliente_id, u.nombre
             FROM factura_compra f
             JOIN carrito c ON c.id = f.carrito_id
             JOIN usuario u ON u.id = c.cliente_id
             WHERE f.estado = 'CANCELADA' AND f.fecha >= ?
               AND EXISTS (SELECT 1 FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO')"
        );
        // El plazo más largo posible (5 días hábiles con un fin de semana) entra holgado en 15 días.
        $consulta->execute([$ahora->modify('-15 days')->format('Y-m-d H:i:s')]);

        return $consulta->fetchAll();
    }

    // Por qué venció una compra ('sin_pago' o 'sin_retirar'), o null si no venció.
    private static function motivoDeVencimiento(array $factura, DateTimeImmutable $ahora): ?string
    {
        $fecha = new DateTimeImmutable($factura['fecha']);

        if ($factura['estado'] === 'PENDIENTE_PAGO' && Compra::vencioSinPagar($fecha, $ahora)) {
            return 'sin_pago';
        }
        if ($factura['estado'] === 'PAGADA' && Compra::vencioSinRetirar($fecha, $ahora)) {
            return 'sin_retirar';
        }

        return null;
    }

    // ---------- Ayudas ----------

    // Cancela una factura ya bloqueada: factura CANCELADA, carrito CANCELADO y el stock vuelve
    // (ENTRADA / VENTA_CANCELADA). $usuarioId es quien la cancela, o null si es automática.
    // Se llama siempre dentro de una transacción. La usan el arrepentimiento y los vencimientos.
    protected function cancelar(array $factura, ?int $usuarioId): void
    {
        $this->db->prepare("UPDATE factura_compra SET estado = 'CANCELADA' WHERE id = ?")->execute([$factura['id']]);
        $this->db->prepare("UPDATE carrito SET estado = 'CANCELADO' WHERE id = ?")->execute([$factura['carrito_id']]);

        foreach ($this->itemsDeFactura((int) $factura['id']) as $item) {
            $this->db->prepare('UPDATE producto SET stock = stock + ? WHERE id = ?')
                ->execute([$item['cantidad'], $item['producto_id']]);
            $this->registrarMovimiento($item['producto_id'], 'ENTRADA', 'VENTA_CANCELADA', $item['cantidad'], $usuarioId, "cancela-{$factura['numero']}-{$item['producto_id']}");
        }
    }

    // Lee la factura y bloquea su fila hasta el final de la transacción.
    protected function bloquearFactura(string $numero): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, f.carrito_id, c.cliente_id
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE f.numero = ? FOR UPDATE"
        );
        $consulta->execute([$numero]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    // Los ítems del carrito con su producto, bloqueando los productos (para confirmar).
    private function itemsParaConfirmar(int $carritoId): array
    {
        $consulta = $this->db->prepare(
            'SELECT i.producto_id, p.nombre, i.cantidad, i.precio_unitario, p.stock, (' . self::A_LA_VENTA . ') AS a_la_venta
             FROM item_carrito i JOIN producto p ON p.id = i.producto_id
             WHERE i.carrito_id = ?
             ORDER BY i.producto_id
             FOR UPDATE'
        );
        $consulta->execute([$carritoId]);

        return array_map(fn (array $fila) => [
            'producto_id' => (int) $fila['producto_id'],
            'nombre' => $fila['nombre'],
            'cantidad' => (int) $fila['cantidad'],
            'precio_unitario' => (float) $fila['precio_unitario'],
            'stock' => (int) $fila['stock'],
            'a_la_venta' => (bool) $fila['a_la_venta'],
        ], $consulta->fetchAll());
    }

    // Los productos de una factura, con el precio del momento.
    protected function itemsDeFactura(int $facturaId): array
    {
        $consulta = $this->db->prepare(
            'SELECT i.producto_id, p.nombre, i.precio_unitario, i.cantidad
             FROM factura_compra f
             JOIN item_carrito i ON i.carrito_id = f.carrito_id
             JOIN producto p ON p.id = i.producto_id
             WHERE f.id = ?
             ORDER BY i.id'
        );
        $consulta->execute([$facturaId]);

        return array_map(fn (array $fila) => [
            'producto_id' => (int) $fila['producto_id'],
            'nombre' => $fila['nombre'],
            'precio_unitario' => (float) $fila['precio_unitario'],
            'cantidad' => (int) $fila['cantidad'],
            'subtotal' => round((float) $fila['precio_unitario'] * (int) $fila['cantidad'], 2),
        ], $consulta->fetchAll());
    }

    // Movimiento de stock de un producto. id_operacion es único: nunca se registra dos veces.
    protected function registrarMovimiento(int $productoId, string $tipo, string $motivo, int $cantidad, ?int $usuarioId, string $idOperacion): void
    {
        $this->db->prepare(
            'INSERT INTO movimiento_inventario (producto_id, tipo, cantidad, motivo, usuario_id, id_operacion)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$productoId, $tipo, $cantidad, $motivo, $usuarioId, $idOperacion]);
    }

    // Una compra con sus plazos (contrato, "Mis compras"): pagar_antes_de solo si falta pagar,
    // retirar_hasta mientras se puede retirar.
    protected function resumen(array $fila): array
    {
        $fecha = new DateTimeImmutable($fila['fecha']);
        $estado = $fila['estado'];

        return [
            'numero' => $fila['numero'],
            'estado' => $estado,
            'fecha' => $fila['fecha'],
            'total' => (float) $fila['total'],
            'pagar_antes_de' => $estado === 'PENDIENTE_PAGO' ? Compra::pagarAntesDe($fecha)->format('Y-m-d H:i:s') : null,
            'retirar_hasta' => in_array($estado, ['PENDIENTE_PAGO', 'PAGADA'], true) ? Compra::retirarHasta($fecha)->format('Y-m-d') : null,
        ];
    }
}
