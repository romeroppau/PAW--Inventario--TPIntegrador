<?php

// Patrón repositorio: esta es la ÚNICA clase que escribe SQL sobre producto y movimiento_inventario.
// - Todas las consultas son parametrizadas ("?"): los datos van separados del SQL (evita la inyección SQL).
// - Traduce los nombres de la tabla a los del contrato con AS (especie_destino -> especie,
//   fecha_vencimiento -> vencimiento), así el resto del sistema no conoce las columnas.
// - Convierte los tipos que devuelve MySQL (DECIMAL llega como texto, BOOLEAN como 0/1) a los del JSON.
class ProductoRepositorio
{
    // Columnas que se devuelven en todos los formatos.
    private const COLUMNAS = 'id, nombre, categoria, especie_destino AS especie, precio,
                              fecha_vencimiento AS vencimiento';

    // Un producto se ve en la tienda si está activo y no está vencido.
    private const VISIBLE_EN_TIENDA = 'activo = 1 AND (fecha_vencimiento IS NULL OR fecha_vencimiento >= CURDATE())';

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
            $condiciones[] = '(nombre LIKE ? OR descripcion LIKE ?)';
            $parametros[] = $patron;
            $parametros[] = $patron;
        }

        if ($categoria !== null) {
            $condiciones[] = 'categoria = ?';
            $parametros[] = $categoria;
        }

        // Filtrar por PERRO o GATO también trae los productos para TODAS las especies.
        if ($especie !== null) {
            $condiciones[] = "especie_destino IN (?, 'TODAS')";
            $parametros[] = $especie;
        }

        $where = implode(' AND ', $condiciones);

        $consulta = $this->db->prepare("SELECT COUNT(*) FROM producto WHERE $where");
        $consulta->execute($parametros);
        $total = (int) $consulta->fetchColumn();

        // LIMIT y OFFSET son enteros calculados acá (nunca texto del usuario), por eso se pueden escribir en el SQL.
        $desde = ($pagina - 1) * $porPagina;
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ", stock > 0 AS disponible
             FROM producto WHERE $where
             ORDER BY nombre, id
             LIMIT $porPagina OFFSET $desde"
        );
        $consulta->execute($parametros);

        return [array_map([$this, 'formatear'], $consulta->fetchAll()), $total];
    }

    // Detalle público, con descripción e imagen. null si no existe, está dado de baja o vencido.
    public function buscarEnTienda(int $id): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', descripcion, stock > 0 AS disponible, imagen, imagen_tipo
             FROM producto WHERE id = ? AND ' . self::VISIBLE_EN_TIENDA
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $this->formatear($fila);
    }

    // ---------- Panel del veterinario ----------

    // Todos los productos, incluidos los dados de baja. Sin imagen ni descripción, para que sea liviano.
    public function listarTodos(): array
    {
        $consulta = $this->db->query(
            'SELECT ' . self::COLUMNAS . ', stock, activo FROM producto ORDER BY nombre, id'
        );

        return array_map([$this, 'formatear'], $consulta->fetchAll());
    }

    // Un producto con todos sus datos, o null si no existe.
    public function buscarPorId(int $id): ?array
    {
        $consulta = $this->db->prepare(
            'SELECT ' . self::COLUMNAS . ', descripcion, stock, activo, imagen, imagen_tipo
             FROM producto WHERE id = ?'
        );
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : $this->formatear($fila);
    }

    public function existe(int $id): bool
    {
        $consulta = $this->db->prepare('SELECT COUNT(*) FROM producto WHERE id = ?');
        $consulta->execute([$id]);

        return (int) $consulta->fetchColumn() > 0;
    }

    // Crea el producto y devuelve su id. Si tiene stock inicial, registra también el movimiento de entrada.
    // Las dos cosas van en una transacción: o se guardan las dos, o ninguna.
    public function insertar(array $datos, int $usuarioId): int
    {
        [$imagen, $imagenTipo] = $datos['imagen'] ?? [null, null];

        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare(
                'INSERT INTO producto
                     (nombre, descripcion, precio, stock, categoria, especie_destino, fecha_vencimiento, imagen, imagen_tipo)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $consulta->bindValue(1, $datos['nombre']);
            $consulta->bindValue(2, $datos['descripcion']);
            $consulta->bindValue(3, number_format($datos['precio'], 2, '.', ''));
            $consulta->bindValue(4, $datos['stock'], PDO::PARAM_INT);
            $consulta->bindValue(5, $datos['categoria']);
            $consulta->bindValue(6, $datos['especie']);
            $consulta->bindValue(7, $datos['vencimiento']);
            $consulta->bindValue(8, $imagen, $imagen === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $consulta->bindValue(9, $imagenTipo);
            $consulta->execute();

            $id = (int) $this->db->lastInsertId();

            if ($datos['stock'] > 0) {
                $this->registrarEntrada($id, $datos['stock'], $usuarioId);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $id;
    }

    // Edita los datos (nunca el stock ni el estado activo).
    // Si en los datos no está la clave "imagen", la imagen actual no se toca.
    public function actualizar(int $id, array $datos): void
    {
        $sql = 'UPDATE producto SET nombre = ?, descripcion = ?, precio = ?, categoria = ?,
                    especie_destino = ?, fecha_vencimiento = ?';
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
        $consulta->bindValue($posicion++, $datos['vencimiento']);

        if (array_key_exists('imagen', $datos)) {
            [$imagen, $imagenTipo] = $datos['imagen'] ?? [null, null];
            $consulta->bindValue($posicion++, $imagen, $imagen === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
            $consulta->bindValue($posicion++, $imagenTipo);
        }

        $consulta->bindValue($posicion, $id, PDO::PARAM_INT);
        $consulta->execute();
    }

    // Baja lógica: el producto queda inactivo y conserva su historial. Nunca se borra la fila.
    public function darDeBaja(int $id): void
    {
        $consulta = $this->db->prepare('UPDATE producto SET activo = 0 WHERE id = ?');
        $consulta->execute([$id]);
    }

    // Suma stock y registra el movimiento de entrada, en una transacción.
    // Devuelve el stock nuevo, o null si el producto no existe o está dado de baja.
    public function cargarStock(int $id, int $cantidad, int $usuarioId): ?int
    {
        $this->db->beginTransaction();
        try {
            // El UPDATE suma sobre el valor de la base (stock = stock + ?), así dos cargas
            // al mismo tiempo no se pisan. Si el producto está inactivo, no cambia ninguna fila.
            $consulta = $this->db->prepare('UPDATE producto SET stock = stock + ? WHERE id = ? AND activo = 1');
            $consulta->execute([$cantidad, $id]);

            if ($consulta->rowCount() === 0) {
                $this->db->rollBack();
                return null;
            }

            $this->registrarEntrada($id, $cantidad, $usuarioId);

            $consulta = $this->db->prepare('SELECT stock FROM producto WHERE id = ?');
            $consulta->execute([$id]);
            $stock = (int) $consulta->fetchColumn();

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $stock;
    }

    // Historial de movimientos de un producto, del más reciente al más antiguo, con el nombre de quien lo registró.
    public function movimientos(int $productoId): array
    {
        $consulta = $this->db->prepare(
            "SELECT m.id, DATE_FORMAT(m.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, m.tipo, m.motivo, m.cantidad,
                    u.nombre AS usuario
             FROM movimiento_inventario m
             LEFT JOIN usuario u ON u.id = m.usuario_id
             WHERE m.producto_id = ?
             ORDER BY m.fecha DESC, m.id DESC"
        );
        $consulta->execute([$productoId]);

        return array_map(fn (array $fila) => [
            'id' => (int) $fila['id'],
            'fecha' => $fila['fecha'],
            'tipo' => $fila['tipo'],
            'motivo' => $fila['motivo'],
            'cantidad' => (int) $fila['cantidad'],
            'usuario' => $fila['usuario'],
        ], $consulta->fetchAll());
    }

    // Movimiento de entrada por una carga del veterinario. Se llama siempre dentro de una transacción.
    private function registrarEntrada(int $productoId, int $cantidad, int $usuarioId): void
    {
        $consulta = $this->db->prepare(
            "INSERT INTO movimiento_inventario (producto_id, tipo, cantidad, motivo, usuario_id)
             VALUES (?, 'ENTRADA', ?, 'CARGA_VETERINARIO', ?)"
        );
        $consulta->execute([$productoId, $cantidad, $usuarioId]);
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

        return $producto;
    }
}
