<?php

// Patrón repositorio: esta es la ÚNICA clase que escribe SQL sobre producto y movimiento_inventario.
// - Todas las consultas son parametrizadas ("?"): los datos van separados del SQL (evita la inyección SQL).
// - Traduce los nombres de la tabla a los del contrato con AS (especie_destino -> especie,
//   fecha_vencimiento -> vencimiento), así el resto del sistema no conoce las columnas.
// - Convierte los tipos que devuelve MySQL (DECIMAL llega como texto, BOOLEAN como 0/1) a los del JSON.
// - El stock está en los lotes (migración 028, clase Lotes): cargar y retirar cambian un lote, y
//   "vencimiento" es el del próximo lote con stock que vence.
class ProductoRepositorio
{
    // Columnas que se devuelven en todos los formatos (tabla producto "p").
    private const COLUMNAS = 'p.id, p.nombre, p.categoria, p.especie_destino AS especie, p.precio, '
        . Lotes::PROXIMO_VENCIMIENTO . ' AS vencimiento';

    // Un producto se ve en la tienda si está activo y no tiene todo su stock vencido.
    private const VISIBLE_EN_TIENDA = Lotes::A_LA_VENTA;

    // Las columnas de la tienda: las de siempre y la promoción vigente, si tiene (migración 016), con
    // cuántas unidades le quedan a su lote (028).
    // Van con "p." porque la consulta también trae la tabla promocion (que tiene su propio id).
    private const COLUMNAS_TIENDA = self::COLUMNAS . ', ' . Lotes::STOCK_VENDIBLE . " > 0 AS disponible,
                                     pr.porcentaje AS promocion_porcentaje,
                                     DATE_FORMAT(pr.hasta, '%Y-%m-%d') AS promocion_hasta, lp.stock AS promocion_quedan,
                                     " . Promocion::PRECIO_FINAL . " AS promocion_precio";

    // Recibe la conexión de afuera (Database::conectar() en la aplicación), así las pruebas pueden usar otra base.
    // MySQL trabaja en UTC: se le indica la hora de Argentina (sin horario de verano, siempre -03:00),
    // así las fechas de los movimientos y el CURDATE() de los vencimientos son los de acá.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // ---------- Tienda (público) ----------

    // Catálogo con búsqueda, filtros y paginado. Devuelve [productos de la página, total sin paginar].
    public function listarTienda(?string $buscar, ?string $categoria, ?string $especie, int $pagina, int $porPagina): array
    {
        $condiciones = [self::VISIBLE_EN_TIENDA];
        $parametros = [];

        if ($buscar !== null) {
            // % y _ son comodines de LIKE: se escapan para que se busquen como texto común.
            $patron = '%' . addcslashes($buscar, '%_\\') . '%';
            $condiciones[] = '(p.nombre LIKE ? OR p.descripcion LIKE ?)';
            $parametros[] = $patron;
            $parametros[] = $patron;
        }

        if ($categoria !== null) {
            $condiciones[] = 'p.categoria = ?';
            $parametros[] = $categoria;
        }

        // Filtrar por PERRO o GATO también trae los productos para TODAS las especies.
        if ($especie !== null) {
            $condiciones[] = "p.especie_destino IN (?, 'TODAS')";
            $parametros[] = $especie;
        }

        $where = implode(' AND ', $condiciones);

        $consulta = $this->db->prepare("SELECT COUNT(*) FROM producto p WHERE $where");
        $consulta->execute($parametros);
        $total = (int) $consulta->fetchColumn();

        // LIMIT y OFFSET son enteros calculados acá (nunca texto del usuario), por eso se pueden escribir en el SQL.
        $desde = ($pagina - 1) * $porPagina;
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_TIENDA . '
             FROM producto p ' . Promocion::JOIN_VIGENTE . "
             WHERE $where
             ORDER BY p.nombre, p.id
             LIMIT $porPagina OFFSET $desde"
        );
        $consulta->execute($parametros);

        return [array_map([$this, 'formatear'], $consulta->fetchAll()), $total];
    }

    // Detalle público, con descripción e imagen. null si no existe, está dado de baja o vencido.
    public function buscarEnTienda(int $id): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS_TIENDA . ', p.descripcion, p.imagen, p.imagen_tipo
             FROM producto p ' . Promocion::JOIN_VIGENTE . '
             WHERE p.id = ? AND ' . self::VISIBLE_EN_TIENDA
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $this->formatear($fila);
    }

    // Los productos en promoción que se pueden comprar (con stock), el descuento más grande primero.
    // Es la sección "Promociones" de la tienda: son pocos, así que no se pagina.
    public function enPromocion(): array
    {
        $consulta = $this->db->query(
            'SELECT ' . self::COLUMNAS_TIENDA . '
             FROM producto p ' . Promocion::JOIN_VIGENTE . '
             WHERE pr.id IS NOT NULL AND ' . self::VISIBLE_EN_TIENDA . '
             ORDER BY pr.porcentaje DESC, p.nombre, p.id'
        );

        return array_map([$this, 'formatear'], $consulta->fetchAll());
    }

    // ---------- Panel del veterinario ----------

    // Todos los productos, incluidos los dados de baja, con sus lotes. Sin imagen ni descripción, para
    // que sea liviano.
    public function listarTodos(): array
    {
        $consulta = $this->db->query(
            'SELECT ' . self::COLUMNAS . ', p.stock, ' . Lotes::STOCK_VENDIBLE . ' AS stock_vendible, p.activo
             FROM producto p ORDER BY p.nombre, p.id'
        );
        $lotes = (new Lotes($this->db))->deProductos();

        return array_map(
            fn (array $fila) => $this->formatear($fila) + ['lotes' => $lotes[(int) $fila['id']] ?? []],
            $consulta->fetchAll()
        );
    }

    // Un producto con todos sus datos y sus lotes, o null si no existe.
    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', p.descripcion, p.stock, ' . Lotes::STOCK_VENDIBLE . ' AS stock_vendible,
                    p.activo, p.imagen, p.imagen_tipo
             FROM producto p WHERE p.id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();
        if ($fila === false) {
            return null;
        }

        return $this->formatear($fila) + ['lotes' => (new Lotes($this->db))->deProductos($id)[$id] ?? []];
    }

    public function existe(int $id): bool
    {
        $consulta = $this->db->prepare('SELECT COUNT(*) FROM producto WHERE id = ?');
        $consulta->execute([$id]);

        return (int) $consulta->fetchColumn() > 0;
    }

    // Crea el producto y devuelve su id. Si tiene stock inicial, vencimiento o número de lote, crea su
    // primer lote y registra el movimiento de entrada.
    // Todo va en una transacción: o se guarda todo, o nada.
    public function insertar(array $datos, int $usuarioId): int
    {
        [$imagen, $imagenTipo] = $datos['imagen'] ?? [null, null];

        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare(
                'INSERT INTO producto
                     (nombre, descripcion, precio, stock, categoria, especie_destino, imagen, imagen_tipo)
                 VALUES (?, ?, ?, 0, ?, ?, ?, ?)'
            );
            $consulta->bindValue(1, $datos['nombre']);
            $consulta->bindValue(2, $datos['descripcion']);
            $consulta->bindValue(3, number_format($datos['precio'], 2, '.', ''));
            $consulta->bindValue(4, $datos['categoria']);
            $consulta->bindValue(5, $datos['especie']);
            $consulta->bindValue(6, $imagen, $imagen === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $consulta->bindValue(7, $imagenTipo);
            $consulta->execute();

            $id = (int) $this->db->lastInsertId();

            if ($datos['stock'] > 0 || $datos['vencimiento'] !== null || $datos['lote'] !== null) {
                $lotes = new Lotes($this->db);
                $loteId = $lotes->crear($id, $datos['lote'], $datos['vencimiento']);
                if ($datos['stock'] > 0) {
                    $lotes->sumar($loteId, $datos['stock']);
                    $this->registrarMovimiento($id, 'ENTRADA', 'CARGA_VETERINARIO', $datos['stock'], $usuarioId, $loteId);
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

    // Edita los datos (nunca el stock, los lotes ni el estado activo: el vencimiento es de cada lote).
    // Si en los datos no está la clave "imagen", la imagen actual no se toca.
    public function actualizar(int $id, array $datos): void
    {
        $sql = 'UPDATE producto SET nombre = ?, descripcion = ?, precio = ?, categoria = ?,
                    especie_destino = ?';
        if (array_key_exists('imagen', $datos)) {
            $sql .= ', imagen = ?, imagen_tipo = ?';
        }
        $sql .= ' WHERE id = ?';

        $consulta = $this->db->prepare($sql);
        $posicion = 1;
        $consulta->bindValue($posicion++, $datos['nombre']);
        $consulta->bindValue($posicion++, $datos['descripcion']);
        $consulta->bindValue($posicion++, number_format($datos['precio'], 2, '.', ''));
        $consulta->bindValue($posicion++, $datos['categoria']);
        $consulta->bindValue($posicion++, $datos['especie']);

        if (array_key_exists('imagen', $datos)) {
            [$imagen, $imagenTipo] = $datos['imagen'] ?? [null, null];
            $consulta->bindValue($posicion++, $imagen, $imagen === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $consulta->bindValue($posicion++, $imagenTipo);
        }

        $consulta->bindValue($posicion, $id, PDO::PARAM_INT);
        $consulta->execute();
    }

    // Reactivar: deshace la baja lógica.
    public function reactivar(int $id): void
    {
        $consulta = $this->db->prepare('UPDATE producto SET activo = 1 WHERE id = ?');
        $consulta->execute([$id]);
    }

    // Baja lógica: el producto queda inactivo y conserva su historial. Nunca se borra la fila.
    public function darDeBaja(int $id): void
    {
        $consulta = $this->db->prepare('UPDATE producto SET activo = 0 WHERE id = ?');
        $consulta->execute([$id]);
    }

    // Suma stock a un lote y registra el movimiento de entrada, en una transacción. El lote es
    // $loteId (de ese producto), uno nuevo ($nuevo: {codigo, vencimiento}) o, si no viene ninguno, el
    // lote sin vencimiento (los accesorios no muestran lotes).
    // Devuelve ['stock' => total, 'lote' => el lote], o 'baja' (dado de baja) o 'lote_inexistente'.
    public function cargarStock(int $id, int $cantidad, int $usuarioId, ?int $loteId = null, ?array $nuevo = null): array|string
    {
        $this->db->beginTransaction();
        try {
            // Se bloquea el producto: dos cargas al mismo tiempo esperan una a la otra.
            $consulta = $this->db->prepare('SELECT activo FROM producto WHERE id = ? FOR UPDATE');
            $consulta->execute([$id]);
            if (!(bool) $consulta->fetchColumn()) {
                $this->db->rollBack();
                return 'baja';
            }

            $lotes = new Lotes($this->db);
            if ($loteId !== null) {
                if ($lotes->buscar($id, $loteId) === null) {
                    $this->db->rollBack();
                    return 'lote_inexistente';
                }
            } elseif ($nuevo !== null) {
                $loteId = $lotes->crear($id, $nuevo['codigo'], $nuevo['vencimiento']);
            } else {
                $loteId = $lotes->sinVencimiento($id);
            }

            $lotes->sumar($loteId, $cantidad);
            $this->registrarMovimiento($id, 'ENTRADA', 'CARGA_VETERINARIO', $cantidad, $usuarioId, $loteId);
            $lotes->sincronizar($id);
            $resultado = ['stock' => $this->stock($id), 'lote' => $lotes->buscar($id, $loteId)];

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $resultado;
    }

    // Resta stock a mano (un producto vencido, roto o que se usó en el local) y registra la SALIDA
    // con motivo AJUSTE ("Retiro manual" en la pantalla), en una transacción. De $loteId, o si no viene,
    // de los lotes que vencen primero (los vencidos antes que nada).
    // Resta solo si alcanza: dos retiros al mismo tiempo no dejan el stock negativo.
    // Devuelve el stock nuevo, o 'baja', 'lote_inexistente' o ['insuficiente', lo que hay].
    public function retirarStock(int $id, int $cantidad, int $usuarioId, ?int $loteId = null): int|string|array
    {
        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare('SELECT activo, stock FROM producto WHERE id = ? FOR UPDATE');
            $consulta->execute([$id]);
            $producto = $consulta->fetch();
            if ($producto === false || !(bool) $producto['activo']) {
                $this->db->rollBack();
                return 'baja';
            }

            $lotes = new Lotes($this->db);
            if ($loteId !== null) {
                $lote = $lotes->buscar($id, $loteId);
                if ($lote === null) {
                    $this->db->rollBack();
                    return 'lote_inexistente';
                }
                $partes = $lotes->restar($loteId, $cantidad) ? [[$loteId, $cantidad]] : null;
                $hay = $lote['stock'];
            } else {
                $partes = $lotes->sacar($id, $cantidad, null, true);
                $hay = (int) $producto['stock'];
            }
            if ($partes === null) {
                $this->db->rollBack();
                return ['insuficiente', $hay];
            }

            foreach ($partes as [$deLote, $tomadas]) {
                $this->registrarMovimiento($id, 'SALIDA', 'AJUSTE', $tomadas, $usuarioId, $deLote);
            }
            $lotes->sincronizar($id);
            $stock = $this->stock($id);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $stock;
    }

    // Corrige el número o el vencimiento de un lote (por ejemplo, se cargó mal). $datos: {codigo,
    // vencimiento}, ya validados. false si el lote no es de ese producto.
    public function editarLote(int $id, int $loteId, array $datos): bool
    {
        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare('UPDATE lote_producto SET codigo = ?, vencimiento = ? WHERE id = ? AND producto_id = ?');
            $consulta->execute([$datos['codigo'], $datos['vencimiento'], $loteId, $id]);
            $existe = (new Lotes($this->db))->buscar($id, $loteId) !== null;
            (new Lotes($this->db))->sincronizar($id);
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $existe;
    }

    private function stock(int $id): int
    {
        $consulta = $this->db->prepare('SELECT stock FROM producto WHERE id = ?');
        $consulta->execute([$id]);

        return (int) $consulta->fetchColumn();
    }

    // Historial de movimientos de un producto, del más reciente al más antiguo, con el nombre de quien lo registró.
    public function movimientos(int $productoId): array
    {
        return $this->todosLosMovimientos($productoId);
    }

    // Los movimientos de los productos (o de uno), del más reciente al más antiguo. $desde y $hasta
    // (AAAA-MM-DD, inclusive) filtran por el día. Cada uno trae el producto y de dónde salió
    // (consulta_id o compra, ReferenciaMovimiento).
    public function todosLosMovimientos(?int $productoId = null, ?string $desde = null, ?string $hasta = null): array
    {
        $sql = "SELECT m.id, DATE_FORMAT(m.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, m.tipo, m.motivo, m.cantidad,
                       m.producto_id, p.nombre AS producto, u.nombre AS usuario, m.id_operacion,
                       l.id AS lote_id, l.codigo AS lote_codigo, DATE_FORMAT(l.vencimiento, '%Y-%m-%d') AS lote_vencimiento
                FROM movimiento_inventario m
                JOIN producto p ON p.id = m.producto_id
                LEFT JOIN lote_producto l ON l.id = m.lote_id
                LEFT JOIN usuario u ON u.id = m.usuario_id
                WHERE 1 = 1";
        $parametros = [];
        if ($productoId !== null) {
            $sql .= ' AND m.producto_id = ?';
            $parametros[] = $productoId;
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
            'producto_id' => (int) $fila['producto_id'],
            'producto' => $fila['producto'],
            'usuario' => $fila['usuario'],
            // De qué lote (028): {id, codigo, vencimiento}, o null en los de antes sin lote.
            'lote' => $fila['lote_id'] === null ? null : [
                'id' => (int) $fila['lote_id'],
                'codigo' => $fila['lote_codigo'],
                'vencimiento' => $fila['lote_vencimiento'],
            ],
        ] + ReferenciaMovimiento::de($fila['id_operacion']), $consulta->fetchAll());
    }

    // Se llama siempre dentro de una transacción.
    private function registrarMovimiento(int $productoId, string $tipo, string $motivo, int $cantidad, int $usuarioId, int $loteId): void
    {
        $consulta = $this->db->prepare(
            'INSERT INTO movimiento_inventario (producto_id, lote_id, tipo, cantidad, motivo, usuario_id)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $consulta->execute([$productoId, $loteId, $tipo, $cantidad, $motivo, $usuarioId]);
    }

    // Pasa una fila de MySQL al formato del contrato: números como números, booleanos como booleanos,
    // y la imagen (bytes + tipo) como data URL. Solo convierte las columnas que la consulta trajo.
    private function formatear(array $fila): array
    {
        $producto = [
            'id' => (int) $fila['id'],
            'nombre' => $fila['nombre'],
        ];

        if (array_key_exists('descripcion', $fila)) {
            $producto['descripcion'] = $fila['descripcion'];
        }

        $producto['categoria'] = $fila['categoria'];
        $producto['especie'] = $fila['especie'];
        $producto['precio'] = (float) $fila['precio'];

        if (array_key_exists('stock', $fila)) {
            $producto['stock'] = (int) $fila['stock'];
        }
        // Lo que se puede vender (028): el stock sin los lotes vencidos.
        if (array_key_exists('stock_vendible', $fila)) {
            $producto['stock_vendible'] = (int) $fila['stock_vendible'];
        }

        // El vencimiento del próximo lote con stock que vence (null si ninguno vence).
        $producto['vencimiento'] = $fila['vencimiento'];

        if (array_key_exists('disponible', $fila)) {
            $producto['disponible'] = (bool) $fila['disponible'];
        }
        if (array_key_exists('activo', $fila)) {
            $producto['activo'] = (bool) $fila['activo'];
        }
        if (array_key_exists('imagen', $fila)) {
            $producto['imagen'] = Imagen::aDataUrl($fila['imagen'], $fila['imagen_tipo']);
        }
        // En la tienda: la promoción vigente ({porcentaje, precio con descuento, hasta}) o null.
        if (array_key_exists('promocion_porcentaje', $fila)) {
            $producto['promocion'] = $fila['promocion_porcentaje'] === null ? null : [
                'porcentaje' => (int) $fila['promocion_porcentaje'],
                'precio' => (float) $fila['promocion_precio'],
                'hasta' => $fila['promocion_hasta'],
                // Las unidades con descuento: las que le quedan al lote de la promoción (028).
                'quedan' => (int) $fila['promocion_quedan'],
            ];
        }

        return $producto;
    }
}
