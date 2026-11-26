<?php

// La clase Insumo del UML (diagrama de clases): un material interno de la veterinaria.
// Tiene las reglas del insumo y nada de SQL. La usa InsumoRepositorio, que es el que lee
// y guarda en la base.
// Contrato: docs/api/contrato-insumos.md.
class Insumo
{
    // Con esta cantidad o menos, el insumo tiene stock bajo (regla de reposición del plan). Es el valor
    // por defecto: la veterinaria lo cambia en Configuración (limite_stock_bajo, Entrega 5).
    public const LIMITE_STOCK_BAJO = 10;

    // Qué aplicación del calendario sanitario puede ser un insumo (migración 025): la consulta de una
    // vacuna o desparasitación tiene que llevar un insumo de esa aplicación.
    public const APLICACIONES = ['SEXTUPLE', 'TRIPLE_FELINA', 'ANTIRRABICA', 'TOS_PERRERAS', 'LEUCEMIA_FELINA', 'ANTIPARASITARIO'];

    // El límite de stock bajo configurado (el mismo para insumos y productos), o 10 si no hay.
    public static function limiteStockBajo(): int
    {
        return class_exists('Configuracion')
            ? Configuracion::entero('limite_stock_bajo', self::LIMITE_STOCK_BAJO, 0, 1000)
            : self::LIMITE_STOCK_BAJO;
    }

    public function __construct(
        public int $id,
        public string $nombre,
        public int $stock,
        public float $costo,
        public bool $activo,
        // true si el veterinario lo marcó "Ya pedido" al proveedor. Se borra al cargar stock.
        public bool $pedido = false,
        // La aplicación del calendario (APLICACIONES), o null si no es una vacuna ni un antiparasitario.
        public ?string $aplicacion = null
    ) {
    }

    // Arma un Insumo a partir de una fila de la tabla insumo (MySQL devuelve todo como texto).
    public static function desdeFila(array $fila): self
    {
        return new self(
            (int) $fila['id'],
            $fila['nombre'],
            (int) $fila['stock'],
            (float) $fila['costo'],
            (bool) $fila['activo'],
            ($fila['estado'] ?? '') === 'YA_PEDIDO',
            $fila['aplicacion'] ?? null
        );
    }

    // El estado sale del stock, salvo que el veterinario lo haya marcado "Ya pedido":
    // así se ve que el faltante ya está resuelto y no se vuelve a pedir.
    public function estado(): string
    {
        if ($this->pedido) {
            return 'YA_PEDIDO';
        }

        return $this->stock === 0 ? 'EN_FALTA' : 'DISPONIBLE';
    }

    // Marca el insumo como pedido al proveedor. Solo tiene sentido con stock bajo (el límite configurado):
    // devuelve false si el insumo tiene stock suficiente.
    public function marcarPedido(): bool
    {
        if (!$this->stockBajo()) {
            return false;
        }

        $this->pedido = true;
        return true;
    }

    // UML: stockBajo(). El límite configurado o menos (un insumo en falta también tiene stock bajo).
    public function stockBajo(): bool
    {
        return $this->stock <= self::limiteStockBajo();
    }

    // UML: reponer(cantidad). Carga de stock.
    public function reponer(int $cantidad): void
    {
        $this->stock += $cantidad;
        // Llegó el pedido: deja de estar "Ya pedido" y el estado vuelve a salir del stock.
        $this->pedido = false;
    }

    // UML: descontarStock(cantidad). Retiro manual.
    // Devuelve false si no alcanza: el stock nunca queda negativo.
    public function descontarStock(int $cantidad): bool
    {
        if ($cantidad > $this->stock) {
            return false;
        }

        $this->stock -= $cantidad;
        return true;
    }

    // El insumo con los campos del contrato, listo para Response::json.
    public function aJson(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'aplicacion' => $this->aplicacion,
            'stock' => $this->stock,
            'costo' => $this->costo,
            'estado' => $this->estado(),
            'stock_bajo' => $this->stockBajo(),
            'activo' => $this->activo
        ];
    }
}
