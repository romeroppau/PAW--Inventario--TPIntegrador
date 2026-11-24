<?php

// El SQL de las compras de la tienda (factura_compra, y el carrito y el stock que mueven).
// Contrato: docs/api/contrato-tienda.md. Las reglas de plazos y avisos están en la clase Compra.
// - Todas las consultas son parametrizadas ("?").
// - Confirmar y cancelar mueven el stock: van en una transacción y bloquean las filas que tocan
//   (SELECT ... FOR UPDATE), así dos pedidos al mismo tiempo no se pisan.
class CompraRepositorio
{
    // Los plazos de una compra (migración 015): los guardados o, si una compra no los tiene, calculados
    // como antes (15 minutos y 5 días hábiles). Se usan en el WHERE y el ORDER BY; en los SELECT van
    // ya formateados como pagar_antes_de y retirar_hasta.
    protected const RETIRAR_HASTA = "COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY)";
    protected const PAGAR_ANTES_DE = "COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE)";

    // La política de reintegro de una compra (migración 017): la guardada o, si la compra es anterior,
    // los valores por defecto. Y el reintegro de una cancelada por el comprador (si hay).
    protected const REINTEGRO = 'COALESCE(f.reintegro_anticipado, ' . Compra::REINTEGRO_ANTICIPADO . ') AS reintegro_anticipado, '
        . 'COALESCE(f.reintegro_ultimo_momento, ' . Compra::REINTEGRO_ULTIMO_MOMENTO . ') AS reintegro_ultimo_momento, '
        . 'COALESCE(f.reintegro_horas, ' . Compra::HORAS_ANTICIPACION_REINTEGRO . ') AS reintegro_horas, '
        . 'f.reintegro_porcentaje, f.reintegro_monto, f.reintegro_estado';

    protected const A_LA_VENTA = 'p.activo = 1 AND (p.fecha_vencimiento IS NULL OR p.fecha_vencimiento >= CURDATE())';

    // Hora de Argentina (-03:00): la fecha de la factura y de los movimientos.
    public function __construct(protected PDO $db)
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
            // La compra guarda sus plazos con la configuración de este momento: si después la
            // veterinaria los cambia, a esta compra no le cambia lo prometido.
            $ahora = new DateTimeImmutable();
            $consulta = $this->db->prepare(
                "INSERT INTO factura_compra (numero, carrito_id, total, estado, fecha, pagar_antes_de, retirar_hasta,
                                             reintegro_anticipado, reintegro_ultimo_momento, reintegro_horas)
                 VALUES (?, ?, ?, 'PENDIENTE_PAGO', ?, ?, ?, ?, ?, ?)"
            );
            $reintegro = Compra::politicaDeReintegro();
            $consulta->execute([
                'V-TMP-' . $carritoId, $carritoId, number_format($total, 2, '.', ''),
                $ahora->format('Y-m-d H:i:s'),
                Compra::pagarAntesDe($ahora)->format('Y-m-d H:i:s'),
                Compra::retirarHasta($ahora)->format('Y-m-d'),
                $reintegro['anticipado'], $reintegro['ultimo_momento'], $reintegro['horas'],
            ]);
            $facturaId = (int) $this->db->lastInsertId();
            $numero = Compra::numero($facturaId);
            $this->db->prepare('UPDATE factura_compra SET numero = ? WHERE id = ?')->execute([$numero, $facturaId]);

            // La reserva del stock, con su movimiento (un id_operacion por producto: nunca se repite).
            // Cada ítem guarda el precio que se cobró: la factura lo muestra aunque después cambie el
            // precio del producto o termine la promoción.
            foreach ($items as $item) {
                $this->db->prepare('UPDATE item_carrito SET precio_unitario = ? WHERE carrito_id = ? AND producto_id = ?')
                    ->execute([number_format($item['precio_unitario'], 2, '.', ''), $carritoId, $item['producto_id']]);
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

    // Las compras de un comprador hechas entre $desde y $hasta (fechas "Y-m-d", inclusive), de la más
    // nueva a la más vieja, de a $porPagina. Devuelve [compras, hayMas].
    public function delComprador(int $compradorId, string $desde, string $hasta, int $pagina, int $porPagina): array
    {
        $consulta = $this->db->prepare(
            "SELECT f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha,
                    DATE_FORMAT(" . self::PAGAR_ANTES_DE . ", '%Y-%m-%d %H:%i:%s') AS pagar_antes_de,
                    DATE_FORMAT(" . self::RETIRAR_HASTA . ", '%Y-%m-%d') AS retirar_hasta, f.total, f.motivo_cancelacion, (EXISTS (SELECT 1 FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO')) AS tiene_factura,
                    " . self::REINTEGRO . "
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE c.cliente_id = ? AND DATE(f.fecha) BETWEEN ? AND ?
             ORDER BY f.fecha DESC, f.id DESC
             LIMIT " . ($porPagina + 1) . ' OFFSET ' . (($pagina - 1) * $porPagina)
        );
        $consulta->execute([$compradorId, $desde, $hasta]);

        return self::paginar(array_map([$this, 'resumen'], $consulta->fetchAll()), $porPagina);
    }

    // Una compra con sus productos. Con $compradorId, solo si es de ese comprador (si no, null).
    public function detalle(string $numero, ?int $compradorId = null): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.total, f.motivo_cancelacion, (EXISTS (SELECT 1 FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO')) AS tiene_factura, " . self::REINTEGRO . ",
                    DATE_FORMAT(f.entregada_en, '%Y-%m-%d %H:%i:%s') AS entregada_en, c.cliente_id,
                    (SELECT p.id_externo FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO'
                     ORDER BY p.id DESC LIMIT 1) AS operacion_mp
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
        // El número de operación de Mercado Pago del pago aprobado (el comprobante), o null.
        $compra['operacion_mp'] = $fila['operacion_mp'];
        $compra['items'] = $this->itemsDeFactura((int) $fila['id']);

        return $compra;
    }

    // ---------- Para los avisos ----------

    // El comprador de una compra, con lo que necesitan los avisos: ['cliente_id', 'nombre', 'fecha', 'total', 'estado'] o null.
    public function comprador(string $numero): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT c.cliente_id, u.nombre, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.total, f.estado
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id JOIN usuario u ON u.id = c.cliente_id
             WHERE f.numero = ?"
        );
        $consulta->execute([$numero]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    // Los ids de todas las veterinarias (rol VETERINARIO): reciben el aviso de cada pedido pagado.
    public function veterinarias(): array
    {
        return array_map('intval', $this->db->query("SELECT id FROM usuario WHERE rol = 'VETERINARIO' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
    }

    // ---------- Veterinaria ----------

    // Pedidos de una pestaña de la veterinaria (PAGADA, ENTREGADA o CANCELADA), con el comprador y
    // sus productos, de a $porPagina. Devuelve [pedidos, hayMas].
    // - $buscar: número de pedido o nombre del comprador.
    // - Para retirar (PAGADA): del que vence antes al que vence después; con $venceHoy, solo los que
    //   tienen hoy su último día para retirar.
    // - Entregados y cancelados (historial): entre $desde y $hasta (por la fecha de entrega o de
    //   cancelación), los más recientes primero.
    public function deLaVeterinaria(string $estado, string $buscar, ?string $desde, ?string $hasta, bool $venceHoy, int $pagina, int $porPagina): array
    {
        $orden = match ($estado) {
            'PAGADA' => self::RETIRAR_HASTA . ', f.fecha, f.id',
            'ENTREGADA' => 'f.entregada_en DESC, f.id DESC',
            default => 'f.cancelada_en DESC, f.id DESC',
        };

        $sql = "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.total,
                       f.motivo_cancelacion, (EXISTS (SELECT 1 FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO')) AS tiene_factura, " . self::REINTEGRO . ", u.nombre AS comprador,
                       DATE_FORMAT(f.entregada_en, '%Y-%m-%d %H:%i:%s') AS entregada_en, v.nombre AS entregada_por
                FROM factura_compra f
                JOIN carrito c ON c.id = f.carrito_id
                JOIN usuario u ON u.id = c.cliente_id
                LEFT JOIN usuario v ON v.id = f.entregada_por
                WHERE f.estado = ?";
        $parametros = [$estado];

        if ($estado === 'PAGADA' && $venceHoy) {
            $sql .= ' AND ' . self::RETIRAR_HASTA . ' = CURDATE()';
        }
        if ($estado !== 'PAGADA' && $desde !== null && $hasta !== null) {
            $sql .= $estado === 'ENTREGADA'
                ? ' AND DATE(f.entregada_en) BETWEEN ? AND ?'
                : ' AND DATE(COALESCE(f.cancelada_en, f.fecha)) BETWEEN ? AND ?';
            array_push($parametros, $desde, $hasta);
        }

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

        $consulta = $this->db->prepare("$sql ORDER BY $orden LIMIT " . ($porPagina + 1) . ' OFFSET ' . (($pagina - 1) * $porPagina));
        $consulta->execute($parametros);

        return self::paginar(array_map(function (array $fila) use ($estado) {
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
        }, $consulta->fetchAll()), $porPagina);
    }

    // Para la pantalla de la veterinaria, sin traer los pedidos: cuántos hay para retirar y cuántos
    // tienen hoy su último día.
    public function conteoParaRetirar(): array
    {
        $fila = $this->db->query(
            "SELECT COUNT(*) AS para_retirar, SUM(" . self::RETIRAR_HASTA . " = CURDATE()) AS vencen_hoy
             FROM factura_compra f WHERE f.estado = 'PAGADA'"
        )->fetch();

        return ['para_retirar' => (int) $fila['para_retirar'], 'vencen_hoy' => (int) ($fila['vencen_hoy'] ?? 0)];
    }

    // La factura de una compra: solo si se pagó (tiene un pago aprobado), aunque después se haya
    // cancelado. Con $compradorId, solo si es de ese comprador. null si no hay factura.
    public function factura(string $numero, ?int $compradorId = null): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha,
                    DATE_FORMAT(" . self::PAGAR_ANTES_DE . ", '%Y-%m-%d %H:%i:%s') AS pagar_antes_de,
                    DATE_FORMAT(" . self::RETIRAR_HASTA . ", '%Y-%m-%d') AS retirar_hasta,
                    f.total, f.motivo_cancelacion, DATE_FORMAT(f.entregada_en, '%Y-%m-%d %H:%i:%s') AS entregada_en,
                    DATE_FORMAT(f.cancelada_en, '%Y-%m-%d %H:%i:%s') AS cancelada_en, " . self::REINTEGRO . ",
                    c.cliente_id, u.nombre AS comprador, u.email, v.nombre AS entregada_por,
                    p.id_externo AS operacion, DATE_FORMAT(p.fecha, '%Y-%m-%d %H:%i:%s') AS pagada_en
             FROM factura_compra f
             JOIN carrito c ON c.id = f.carrito_id
             JOIN usuario u ON u.id = c.cliente_id
             LEFT JOIN usuario v ON v.id = f.entregada_por
             JOIN pago p ON p.id = (SELECT MAX(p2.id) FROM pago p2 WHERE p2.factura_compra_id = f.id AND p2.estado = 'APROBADO')
             WHERE f.numero = ?"
        );
        $consulta->execute([$numero]);
        $fila = $consulta->fetch();

        if ($fila === false || ($compradorId !== null && (int) $fila['cliente_id'] !== $compradorId)) {
            return null;
        }

        $compra = $this->resumen($fila + ['tiene_factura' => 1]);

        return $compra + [
            'comprador' => ['nombre' => $fila['comprador'], 'email' => $fila['email']],
            'pago' => ['medio' => 'Mercado Pago', 'operacion' => $fila['operacion'], 'fecha' => $fila['pagada_en']],
            'entregada_en' => $fila['entregada_en'],
            'entregada_por' => $fila['entregada_por'],
            'cancelada_en' => $fila['cancelada_en'],
            'items' => $this->itemsDeFactura((int) $fila['id']),
        ];
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

    // El comprador cancela su compra: antes de pagar (arrepentimiento) o ya pagada, mientras está
    // para retirar. El stock vuelve; si estaba pagada, el importe no se reintegra (política).
    // Devuelve 'sin_pagar' o 'pagada' (lo que se canceló), o 'no_existe', 'cancelada' o 'entregada'.
    public function cancelarPorComprador(string $numero, int $compradorId): string
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
            if ($factura['estado'] === 'ENTREGADA') {
                $this->db->rollBack();
                return 'entregada';
            }

            $this->cancelar($factura, $compradorId, 'COMPRADOR');

            // Ya pagada: el reintegro que le toca según cuándo cancela (la política de esta compra).
            // Queda PENDIENTE: lo hace Reintegros enseguida (y si Mercado Pago falla, la tarea lo reintenta).
            if ($factura['estado'] === 'PAGADA') {
                $porcentaje = Compra::porcentajeDeReintegro(
                    new DateTimeImmutable($factura['retirar_hasta']), new DateTimeImmutable(),
                    (int) $factura['reintegro_anticipado'], (int) $factura['reintegro_ultimo_momento'], (int) $factura['reintegro_horas']
                );
                $monto = Compra::montoDeReintegro((float) $factura['total'], $porcentaje);
                $this->db->prepare('UPDATE factura_compra SET reintegro_porcentaje = ?, reintegro_monto = ?, reintegro_estado = ? WHERE id = ?')
                    ->execute([$porcentaje, number_format($monto, 2, '.', ''), $monto > 0 ? 'PENDIENTE' : null, $factura['id']]);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $factura['estado'] === 'PAGADA' ? 'pagada' : 'sin_pagar';
    }

    // ---------- Reintegros ----------

    // Los reintegros que faltan hacer (o solo el de $numero): numero, monto y el pago aprobado de
    // Mercado Pago (pago_id) sobre el que se devuelve.
    public function reintegrosPendientes(?string $numero = null): array
    {
        $sql = "SELECT f.numero, f.reintegro_monto AS monto,
                       (SELECT p.id_externo FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO'
                        ORDER BY p.id DESC LIMIT 1) AS pago_id
                FROM factura_compra f
                WHERE f.reintegro_estado = 'PENDIENTE'";
        $parametros = [];
        if ($numero !== null) {
            $sql .= ' AND f.numero = ?';
            $parametros[] = $numero;
        }

        $consulta = $this->db->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    // Mercado Pago aceptó el reintegro. Solo cambia si seguía pendiente (dos corridas no lo marcan dos veces).
    public function marcarReintegroHecho(string $numero): bool
    {
        $consulta = $this->db->prepare(
            "UPDATE factura_compra SET reintegro_estado = 'HECHO', reintegro_en = NOW() WHERE numero = ? AND reintegro_estado = 'PENDIENTE'"
        );
        $consulta->execute([$numero]);

        return $consulta->rowCount() > 0;
    }

    // ---------- Vencimientos ----------
    // Los usa la clase Vencimientos (la tarea programada y la cancelación "al pasar").

    // Los números de las compras vencidas: sin pagar después de su tiempo para pagar, o pagadas que
    // pasaron su último día para retirar (desde la hora de los avisos del día siguiente). El SQL hace
    // un primer filtro con los plazos guardados y la regla exacta la decide Compra.
    public function vencidas(DateTimeImmutable $ahora): array
    {
        $consulta = $this->db->prepare(
            "SELECT f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha,
                    DATE_FORMAT(" . self::PAGAR_ANTES_DE . ", '%Y-%m-%d %H:%i:%s') AS pagar_antes_de,
                    DATE_FORMAT(" . self::RETIRAR_HASTA . ", '%Y-%m-%d') AS retirar_hasta
             FROM factura_compra f
             WHERE (f.estado = 'PENDIENTE_PAGO' AND " . self::PAGAR_ANTES_DE . " <= ?)
                OR (f.estado = 'PAGADA' AND " . self::RETIRAR_HASTA . " < ?)"
        );
        $consulta->execute([$ahora->format('Y-m-d H:i:s'), $ahora->format('Y-m-d')]);

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

            $this->cancelar($factura, null, $motivo === 'sin_pago' ? 'SIN_PAGO' : 'VENCIDA');
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
            "SELECT f.numero, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, c.cliente_id, u.nombre
             FROM factura_compra f
             JOIN carrito c ON c.id = f.carrito_id
             JOIN usuario u ON u.id = c.cliente_id
             WHERE f.estado = 'PAGADA'"
        )->fetchAll();
    }

    // Las compras pagadas que se cancelaron en los últimos días, con el comprador y el motivo: para
    // el aviso de cancelación. Son las que vencieron (VENCIDA) y las que canceló el comprador después
    // de pagar (COMPRADOR con pago aprobado). Con $numero, solo esa.
    public function canceladasPagadasConComprador(DateTimeImmutable $ahora, ?string $numero = null): array
    {
        $sql = "SELECT f.numero, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.motivo_cancelacion,
                       DATE_FORMAT(f.cancelada_en, '%Y-%m-%d %H:%i:%s') AS cancelada_en, c.cliente_id, u.nombre,
                       f.reintegro_porcentaje, f.reintegro_monto
                FROM factura_compra f
                JOIN carrito c ON c.id = f.carrito_id
                JOIN usuario u ON u.id = c.cliente_id
                WHERE f.estado = 'CANCELADA' AND f.cancelada_en >= ?
                  AND (f.motivo_cancelacion = 'VENCIDA'
                       OR (f.motivo_cancelacion = 'COMPRADOR'
                           AND EXISTS (SELECT 1 FROM pago p WHERE p.factura_compra_id = f.id AND p.estado = 'APROBADO')))";
        // Las que se cancelaron hasta hace 2 días alcanzan: cada aviso sale solo el día de la cancelación.
        $parametros = [$ahora->modify('-2 days')->format('Y-m-d H:i:s')];
        if ($numero !== null) {
            $sql .= ' AND f.numero = ?';
            $parametros[] = $numero;
        }

        $consulta = $this->db->prepare($sql);
        $consulta->execute($parametros);

        return $consulta->fetchAll();
    }

    // Por qué venció una compra ('sin_pago' o 'sin_retirar'), o null si no venció.
    private static function motivoDeVencimiento(array $factura, DateTimeImmutable $ahora): ?string
    {
        if ($factura['estado'] === 'PENDIENTE_PAGO' && Compra::vencioSinPagar(new DateTimeImmutable($factura['pagar_antes_de']), $ahora)) {
            return 'sin_pago';
        }
        if ($factura['estado'] === 'PAGADA' && Compra::vencioSinRetirar(new DateTimeImmutable($factura['retirar_hasta']), $ahora)) {
            return 'sin_retirar';
        }

        return null;
    }

    // ---------- Ayudas ----------

    // Cancela una factura ya bloqueada: factura CANCELADA (con el motivo y cuándo), carrito CANCELADO
    // y el stock vuelve (ENTRADA / VENTA_CANCELADA). $usuarioId es quien la cancela, o null si es
    // automática. $motivo: COMPRADOR, SIN_PAGO o VENCIDA.
    // Se llama siempre dentro de una transacción. La usan el comprador y los vencimientos.
    protected function cancelar(array $factura, ?int $usuarioId, string $motivo): void
    {
        $this->db->prepare("UPDATE factura_compra SET estado = 'CANCELADA', motivo_cancelacion = ?, cancelada_en = NOW() WHERE id = ?")
            ->execute([$motivo, $factura['id']]);
        $this->db->prepare("UPDATE carrito SET estado = 'CANCELADO' WHERE id = ?")->execute([$factura['carrito_id']]);

        foreach ($this->itemsDeFactura((int) $factura['id']) as $item) {
            $this->db->prepare('UPDATE producto SET stock = stock + ? WHERE id = ?')
                ->execute([$item['cantidad'], $item['producto_id']]);
            $this->registrarMovimiento($item['producto_id'], 'ENTRADA', 'VENTA_CANCELADA', $item['cantidad'], $usuarioId, $this->idUnico("cancela-{$factura['numero']}-{$item['producto_id']}"));
        }
    }

    // Lee la factura y bloquea su fila hasta el final de la transacción.
    protected function bloquearFactura(string $numero): ?array
    {
        $consulta = $this->db->prepare(
            "SELECT f.id, f.numero, f.estado, DATE_FORMAT(f.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, DATE_FORMAT(COALESCE(f.pagar_antes_de, f.fecha + INTERVAL 15 MINUTE), '%Y-%m-%d %H:%i:%s') AS pagar_antes_de, DATE_FORMAT(COALESCE(f.retirar_hasta, DATE(f.fecha) + INTERVAL (CASE WEEKDAY(f.fecha) WHEN 5 THEN 6 WHEN 6 THEN 5 ELSE 7 END) DAY), '%Y-%m-%d') AS retirar_hasta, f.total, f.carrito_id, c.cliente_id, " . self::REINTEGRO . "
             FROM factura_compra f JOIN carrito c ON c.id = f.carrito_id
             WHERE f.numero = ? FOR UPDATE"
        );
        $consulta->execute([$numero]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $fila;
    }

    // Los ítems del carrito con su producto, bloqueando los productos (para confirmar).
    // El precio es el de hoy, con la promoción vigente si tiene (el mismo que mostró el carrito).
    private function itemsParaConfirmar(int $carritoId): array
    {
        $consulta = $this->db->prepare(
            'SELECT i.producto_id, p.nombre, i.cantidad, ' . Promocion::PRECIO_FINAL . ' AS precio_unitario, p.stock,
                    (' . self::A_LA_VENTA . ') AS a_la_venta
             FROM item_carrito i JOIN producto p ON p.id = i.producto_id
             ' . Promocion::JOIN_VIGENTE . '
             WHERE i.carrito_id = ?
             ORDER BY i.producto_id
             FOR UPDATE OF i, p'
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

    // De una lista con un elemento de más (se pidió $porPagina + 1), devuelve [los $porPagina, hayMas].
    protected static function paginar(array $filas, int $porPagina): array
    {
        return [array_slice($filas, 0, $porPagina), count($filas) > $porPagina];
    }

    // Un id_operacion que todavía no se usó: el base, o el base con -2, -3... Hace falta cuando una
    // compra se cancela, se revive con un pago tardío y se vuelve a cancelar. Se llama dentro de la
    // transacción, con la factura bloqueada: nadie más registra movimientos de esa compra mientras tanto.
    protected function idUnico(string $base): string
    {
        $consulta = $this->db->prepare('SELECT COUNT(*) FROM movimiento_inventario WHERE id_operacion = ? OR id_operacion LIKE ?');
        $consulta->execute([$base, $base . '-%']);
        $usados = (int) $consulta->fetchColumn();

        return $usados === 0 ? $base : $base . '-' . ($usados + 1);
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
        $estado = $fila['estado'];
        $pagarAntesDe = new DateTimeImmutable($fila['pagar_antes_de']);

        return [
            'numero' => $fila['numero'],
            'estado' => $estado,
            'fecha' => $fila['fecha'],
            'total' => (float) $fila['total'],
            'pagar_antes_de' => $estado === 'PENDIENTE_PAGO' ? $fila['pagar_antes_de'] : null,
            // Lo que falta para pagar, contado por el servidor: la cuenta regresiva de la pantalla parte de
            // acá y no del reloj de la computadora del comprador (que puede estar adelantado o atrasado).
            'segundos_para_pagar' => $estado === 'PENDIENTE_PAGO' ? max(0, $pagarAntesDe->getTimestamp() - time()) : null,
            'retirar_hasta' => in_array($estado, ['PENDIENTE_PAGO', 'PAGADA'], true) ? $fila['retirar_hasta'] : null,
            // Los días hábiles de plazo que tuvo esta compra (para el texto de la política).
            'dias_habiles_para_retirar' => Compra::diasHabilesEntre(new DateTimeImmutable($fila['fecha']), new DateTimeImmutable($fila['retirar_hasta'])),
            // Si tiene factura (se pagó, aunque después se haya cancelado): para el enlace "Ver factura".
            'tiene_factura' => (bool) ($fila['tiene_factura'] ?? false),
            // Por qué se canceló: COMPRADOR, SIN_PAGO o VENCIDA (solo en las canceladas).
            'motivo_cancelacion' => $estado === 'CANCELADA' ? ($fila['motivo_cancelacion'] ?? null) : null,
            // Mientras se puede cancelar: cuánto se devolvería (para la política y el "¿Cancelás?").
            'politica_reintegro' => in_array($estado, ['PENDIENTE_PAGO', 'PAGADA'], true) ? self::politicaDeReintegro($fila) : null,
            // Cancelada por el comprador después de pagar: cuánto se le devuelve y si ya se hizo.
            'reintegro' => $estado === 'CANCELADA' && ($fila['reintegro_porcentaje'] ?? null) !== null ? [
                'porcentaje' => (int) $fila['reintegro_porcentaje'],
                'monto' => (float) $fila['reintegro_monto'],
                'estado' => $fila['reintegro_estado'] ?? null,
            ] : null,
        ];
    }

    // La política de reintegro de una compra que todavía se puede cancelar, con los montos.
    // porcentaje_hoy y monto_hoy: lo que se devolvería si cancela ahora (en una pagada).
    private static function politicaDeReintegro(array $fila): array
    {
        $anticipado = (int) $fila['reintegro_anticipado'];
        $ultimoMomento = (int) $fila['reintegro_ultimo_momento'];
        $horas = (int) $fila['reintegro_horas'];
        $retirarHasta = new DateTimeImmutable($fila['retirar_hasta']);
        $total = (float) $fila['total'];
        $hoy = Compra::porcentajeDeReintegro($retirarHasta, new DateTimeImmutable(), $anticipado, $ultimoMomento, $horas);

        return [
            'anticipado' => $anticipado,
            'ultimo_momento' => $ultimoMomento,
            'horas' => $horas,
            'anticipado_hasta' => Compra::reintegroMayorHasta($retirarHasta, $horas)->format('Y-m-d H:i:s'),
            'monto_anticipado' => Compra::montoDeReintegro($total, $anticipado),
            'monto_ultimo_momento' => Compra::montoDeReintegro($total, $ultimoMomento),
            'porcentaje_hoy' => $hoy,
            'monto_hoy' => Compra::montoDeReintegro($total, $hoy),
        ];
    }
}
