<?php

// El SQL del carrito de la tienda (tablas carrito e item_carrito). Contrato: docs/api/contrato-tienda.md.
// - Todas las consultas son parametrizadas ("?").
// - Armar el carrito no toca el stock: solo se controla que no se pida más de lo que hay.
// - Un comprador tiene un solo carrito ABIERTO a la vez: lo asegura la base (índice único sobre la
//   columna calculada "abierto").
// - El carrito muestra siempre el precio de HOY de cada producto, con la promoción vigente si tiene
//   (Promocion::PRECIO_FINAL). La compra cobra ese mismo precio y lo guarda (CompraRepositorio::confirmar).
// - El stock es el que se puede vender (sin los lotes vencidos), y el descuento de una promoción vale
//   solo para las unidades que le quedan a su lote: las demás van a precio normal (migración 028).
class CarritoRepositorio
{
    // Un producto está a la venta si está activo y no tiene todo su stock vencido (la misma regla que
    // el catálogo, ProductoRepositorio::VISIBLE_EN_TIENDA).
    private const A_LA_VENTA = Lotes::A_LA_VENTA;

    // Hora de Argentina (-03:00) para creado_en y el CURDATE() de los vencimientos.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // El id del carrito abierto del comprador, o null si no tiene.
    public function abierto(int $compradorId): ?int
    {
        $consulta = $this->db->prepare("SELECT id FROM carrito WHERE cliente_id = ? AND estado = 'ABIERTO'");
        $consulta->execute([$compradorId]);
        $id = $consulta->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    // El carrito abierto con sus productos y el total. Si no tiene, uno vacío.
    public function contenido(int $compradorId): array
    {
        $carritoId = $this->abierto($compradorId);
        if ($carritoId === null) {
            return ['items' => [], 'total' => 0.0] + self::plazos();
        }

        $consulta = $this->db->prepare(
            'SELECT i.producto_id, p.nombre, ' . Promocion::PRECIO_FINAL . ' AS precio_unitario, p.precio AS precio_lista,
                    pr.porcentaje AS promocion_porcentaje, lp.stock AS promocion_quedan, i.cantidad,
                    ' . Lotes::STOCK_VENDIBLE . ' AS stock,
                    (' . self::A_LA_VENTA . ') AS a_la_venta, (p.imagen IS NOT NULL AND LENGTH(p.imagen) > 0) AS tiene_imagen
             FROM item_carrito i
             JOIN producto p ON p.id = i.producto_id
             ' . Promocion::JOIN_VIGENTE . '
             WHERE i.carrito_id = ?
             ORDER BY i.id'
        );
        $consulta->execute([$carritoId]);

        $items = [];
        $total = 0.0;
        foreach ($consulta->fetchAll() as $fila) {
            $cantidad = (int) $fila['cantidad'];
            $enPromocion = $fila['promocion_porcentaje'] !== null;
            $conDescuento = Promocion::cantidadConDescuento($cantidad, $enPromocion ? (int) $fila['promocion_quedan'] : null);
            $subtotal = Promocion::subtotal($cantidad, (float) $fila['precio_lista'], $conDescuento, (float) $fila['precio_unitario']);
            $total += $subtotal;
            $items[] = [
                'producto_id' => (int) $fila['producto_id'],
                'nombre' => $fila['nombre'],
                // Con promoción, el precio con descuento (el de las unidades que entran en la promoción).
                'precio_unitario' => (float) $fila['precio_unitario'],
                // Con promoción: el precio sin descuento y el porcentaje (si no, null y null).
                'precio_sin_descuento' => $enPromocion ? (float) $fila['precio_lista'] : null,
                'descuento' => $enPromocion ? (int) $fila['promocion_porcentaje'] : null,
                // Con promoción (028): cuántas unidades le quedan al lote y cuántas de estas van con
                // descuento; las demás (cantidad - cantidad_promocion) van a precio_sin_descuento.
                'promocion_quedan' => $enPromocion ? (int) $fila['promocion_quedan'] : null,
                'cantidad_promocion' => $conDescuento,
                'cantidad' => $cantidad,
                'subtotal' => $subtotal,
                'stock' => (int) $fila['stock'],
                'a_la_venta' => (bool) $fila['a_la_venta'],
                // La foto se pide aparte: /api/tienda/productos/{id}/imagen (solo si la tiene).
                'tiene_imagen' => (bool) $fila['tiene_imagen'],
            ];
        }

        return ['items' => $items, 'total' => round($total, 2)] + self::plazos();
    }

    // Los plazos para mostrar antes de pagar: cuántos minutos hay para pagar y, si se compra hoy,
    // hasta qué día se puede retirar (5 días hábiles; la misma cuenta que usa la compra).
    private static function plazos(): array
    {
        return [
            'minutos_para_pagar' => Compra::minutosParaPagar(),
            'dias_habiles_para_retirar' => Compra::diasHabilesParaRetirar(),
            'retirar_hasta_si_compra_hoy' => Compra::retirarHasta(new DateTimeImmutable())->format('Y-m-d'),
            // La política de reintegro si cancela una compra ya pagada: { anticipado, ultimo_momento, horas }.
            'politica_reintegro' => Compra::politicaDeReintegro(),
        ];
    }

    // Agrega un producto (si ya estaba, suma la cantidad). Guarda el precio de hoy, con la promoción
    // si tiene; de todos modos el carrito y la compra usan el precio del momento.
    // Devuelve 'ok', 'no_a_la_venta' o ['stock_insuficiente', stock].
    public function agregar(int $compradorId, int $productoId, int $cantidad): string|array
    {
        $producto = $this->productoALaVenta($productoId);
        if ($producto === null) {
            return 'no_a_la_venta';
        }

        $carritoId = $this->abiertoOCrear($compradorId);
        $yaHay = $this->cantidadEnCarrito($carritoId, $productoId);

        if ($yaHay + $cantidad > $producto['stock']) {
            return ['stock_insuficiente', $producto['stock']];
        }

        $consulta = $this->db->prepare(
            'INSERT INTO item_carrito (carrito_id, producto_id, cantidad, precio_unitario) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad)'
        );
        $consulta->execute([$carritoId, $productoId, $cantidad, $producto['precio']]);

        return 'ok';
    }

    // Cambia la cantidad de un producto que ya está en el carrito.
    // Devuelve 'ok', 'no_esta', 'no_a_la_venta' o ['stock_insuficiente', stock].
    public function cambiar(int $compradorId, int $productoId, int $cantidad): string|array
    {
        $carritoId = $this->abierto($compradorId);
        if ($carritoId === null || $this->cantidadEnCarrito($carritoId, $productoId) === 0) {
            return 'no_esta';
        }

        $producto = $this->productoALaVenta($productoId);
        if ($producto === null) {
            return 'no_a_la_venta';
        }
        if ($cantidad > $producto['stock']) {
            return ['stock_insuficiente', $producto['stock']];
        }

        $consulta = $this->db->prepare('UPDATE item_carrito SET cantidad = ? WHERE carrito_id = ? AND producto_id = ?');
        $consulta->execute([$cantidad, $carritoId, $productoId]);

        return 'ok';
    }

    // Quita un producto del carrito. Devuelve false si no estaba.
    public function quitar(int $compradorId, int $productoId): bool
    {
        $carritoId = $this->abierto($compradorId);
        if ($carritoId === null) {
            return false;
        }

        $consulta = $this->db->prepare('DELETE FROM item_carrito WHERE carrito_id = ? AND producto_id = ?');
        $consulta->execute([$carritoId, $productoId]);

        return $consulta->rowCount() > 0;
    }

    // ---------- Ayudas ----------

    // El precio de hoy (con la promoción, si tiene) y el stock de un producto, o null si no está a la venta.
    private function productoALaVenta(int $productoId): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT ' . Promocion::PRECIO_FINAL . ' AS precio, ' . Lotes::STOCK_VENDIBLE . ' AS stock FROM producto p ' . Promocion::JOIN_VIGENTE
            . ' WHERE p.id = ? AND ' . self::A_LA_VENTA
        );
        $consulta->execute([$productoId]);
        $fila = $consulta->fetch();

        return $fila === false ? null : ['precio' => $fila['precio'], 'stock' => (int) $fila['stock']];
    }

    // El carrito abierto del comprador; si no tiene, lo crea.
    // Si dos pedidos lo crean al mismo tiempo, la base rechaza el segundo (índice único) y se usa el del primero.
    private function abiertoOCrear(int $compradorId): int
    {
        $carritoId = $this->abierto($compradorId);
        if ($carritoId !== null) {
            return $carritoId;
        }

        try {
            $consulta = $this->db->prepare('INSERT INTO carrito (cliente_id, creado_en) VALUES (?, NOW())');
            $consulta->execute([$compradorId]);

            return (int) $this->db->lastInsertId();
        } catch (PDOException $error) {
            if ($error->getCode() !== '23000') {
                throw $error;
            }

            return $this->abierto($compradorId);
        }
    }

    private function cantidadEnCarrito(int $carritoId, int $productoId): int
    {
        $consulta = $this->db->prepare('SELECT cantidad FROM item_carrito WHERE carrito_id = ? AND producto_id = ?');
        $consulta->execute([$carritoId, $productoId]);
        $cantidad = $consulta->fetchColumn();

        return $cantidad === false ? 0 : (int) $cantidad;
    }
}
