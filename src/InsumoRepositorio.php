<?php

// Patrón repositorio: esta es la ÚNICA clase que escribe SQL sobre insumo y sus movimientos
// (movimiento_inventario con insumo_id). Contrato: docs/api/contrato-insumos.md.
// - Todas las consultas son parametrizadas ("?"): los datos van separados del SQL (evita la inyección SQL).
// - Las reglas del insumo (estado según el stock, stock bajo) están en la clase Insumo: acá se
//   usan, no se repiten.
// - Cada vez que cambia el stock se guarda también la columna estado, para que Pacientes, que lee
//   la tabla directo, vea el estado correcto.
class InsumoRepositorio
{
    private const COLUMNAS = 'id, nombre, stock, costo, estado, activo';

    // Recibe la conexión de afuera, así las pruebas pueden usar otra base.
    // Hora de Argentina (-03:00) para las fechas de los movimientos, igual que en productos.
    public function __construct(private PDO $db)
    {
        $this->db->exec("SET time_zone = '-03:00'");
    }

    // Todos los insumos, incluidos los dados de baja, ordenados por nombre.
    // Devuelve una lista de objetos Insumo.
    public function listarTodos(): array
    {
        $consulta = $this->db->query('SELECT ' . self::COLUMNAS . ' FROM insumo ORDER BY nombre, id');

        return array_map([Insumo::class, 'desdeFila'], $consulta->fetchAll());
    }

    // Un insumo, o null si no existe.
    public function buscarPorId(int $id): ?Insumo
    {
        $consulta = $this->db->prepare('SELECT ' . self::COLUMNAS . ' FROM insumo WHERE id = ?');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : Insumo::desdeFila($fila);
    }

    // Crea el insumo y devuelve su id. El estado sale del stock inicial (clase Insumo).
    // Si tiene stock inicial, registra también el movimiento de entrada.
    // Las dos cosas van en una transacción: o se guardan las dos, o ninguna.
    public function insertar(array $datos, int $usuarioId): int
    {
        $nuevo = new Insumo(0, $datos['nombre'], $datos['stock'], $datos['costo'], true);

        $this->db->beginTransaction();
        try {
            $consulta = $this->db->prepare(
                'INSERT INTO insumo (nombre, stock, costo, estado) VALUES (?, ?, ?, ?)'
            );
            $consulta->execute([
                $nuevo->nombre,
                $nuevo->stock,
                number_format($nuevo->costo, 2, '.', ''),
                $nuevo->estado()
            ]);

            $id = (int) $this->db->lastInsertId();

            if ($nuevo->stock > 0) {
                $this->registrarMovimiento($id, 'ENTRADA', 'CARGA_VETERINARIO', $nuevo->stock, $usuarioId);
            }

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $id;
    }

    // Edita nombre y costo. Nunca el stock, el estado ni activo.
    public function actualizar(int $id, array $datos): void
    {
        $consulta = $this->db->prepare('UPDATE insumo SET nombre = ?, costo = ? WHERE id = ?');
        $consulta->execute([$datos['nombre'], number_format($datos['costo'], 2, '.', ''), $id]);
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
            $this->guardarStock($insumo);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $insumo;
    }

    // Carga de stock (UML: reponer). Suma, recalcula el estado y registra la ENTRADA.
    // Devuelve el insumo actualizado, o null si no existe o está dado de baja.
    public function cargarStock(int $id, int $cantidad, int $usuarioId): ?Insumo
    {
        $this->db->beginTransaction();
        try {
            $insumo = $this->bloquear($id);
            if ($insumo === null || !$insumo->activo) {
                $this->db->rollBack();
                return null;
            }

            $insumo->reponer($cantidad);
            $this->guardarStock($insumo);
            $this->registrarMovimiento($id, 'ENTRADA', 'CARGA_VETERINARIO', $cantidad, $usuarioId);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $insumo;
    }

    // Retiro manual, sin consulta (UML: descontarStock). Resta, recalcula el estado y registra
    // la SALIDA con motivo AJUSTE (en la pantalla: "Retiro manual (sin consulta)").
    // Devuelve el insumo actualizado, o null si no existe, está dado de baja o no alcanza el stock.
    public function retirarStock(int $id, int $cantidad, int $usuarioId): ?Insumo
    {
        $this->db->beginTransaction();
        try {
            $insumo = $this->bloquear($id);
            if ($insumo === null || !$insumo->activo || !$insumo->descontarStock($cantidad)) {
                $this->db->rollBack();
                return null;
            }

            $this->guardarStock($insumo);
            $this->registrarMovimiento($id, 'SALIDA', 'AJUSTE', $cantidad, $usuarioId);

            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }

        return $insumo;
    }

    // Historial de movimientos de insumos, del más reciente al más antiguo, con el insumo y el
    // nombre de quien lo registró. Con un id, solo los de ese insumo; con null, los de todos.
    public function movimientos(?int $insumoId = null): array
    {
        $sql = "SELECT m.id, DATE_FORMAT(m.fecha, '%Y-%m-%d %H:%i:%s') AS fecha, m.tipo, m.motivo, m.cantidad,
                       m.insumo_id, i.nombre AS insumo, u.nombre AS usuario
                FROM movimiento_inventario m
                JOIN insumo i ON i.id = m.insumo_id
                LEFT JOIN usuario u ON u.id = m.usuario_id";
        $parametros = [];

        if ($insumoId !== null) {
            $sql .= ' WHERE m.insumo_id = ?';
            $parametros[] = $insumoId;
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
        ], $consulta->fetchAll());
    }

    // Lee el insumo y BLOQUEA su fila hasta el final de la transacción (FOR UPDATE).
    // Si dos personas cargan o retiran al mismo tiempo, la segunda espera a que termine la primera,
    // así ninguna trabaja con un stock viejo. Se llama siempre dentro de una transacción.
    private function bloquear(int $id): ?Insumo
    {
        $consulta = $this->db->prepare('SELECT ' . self::COLUMNAS . ' FROM insumo WHERE id = ? FOR UPDATE');
        $consulta->execute([$id]);
        $fila = $consulta->fetch();

        return $fila === false ? null : Insumo::desdeFila($fila);
    }

    // Guarda el stock y el estado que calculó la clase Insumo.
    private function guardarStock(Insumo $insumo): void
    {
        $consulta = $this->db->prepare('UPDATE insumo SET stock = ?, estado = ? WHERE id = ?');
        $consulta->execute([$insumo->stock, $insumo->estado(), $insumo->id]);
    }

    // Movimiento de un insumo: solo insumo_id (producto_id queda en NULL; la base exige uno de los dos).
    // Se llama siempre dentro de una transacción.
    private function registrarMovimiento(int $insumoId, string $tipo, string $motivo, int $cantidad, int $usuarioId): void
    {
        $consulta = $this->db->prepare(
            'INSERT INTO movimiento_inventario (insumo_id, tipo, cantidad, motivo, usuario_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $consulta->execute([$insumoId, $tipo, $cantidad, $motivo, $usuarioId]);
    }
}
