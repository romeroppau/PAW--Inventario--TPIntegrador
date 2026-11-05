<?php

// La clase Insumo del UML (diagrama de clases): un material interno de la veterinaria.
// Tiene las reglas del insumo y nada de SQL. La usa InsumoRepositorio, que es el que lee
// y guarda en la base.
// Contrato: docs/api/contrato-insumos.md.
class Insumo
{
    // Con esta cantidad o menos, el insumo tiene stock bajo (regla de reposición del plan).
    public const LIMITE_STOCK_BAJO = 10;

    public function __construct(
        public int $id,
        public string $nombre,
        public int $stock,
        public float $costo,
        public bool $activo
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
            (bool) $fila['activo']
        );
    }

    // El estado no se elige: sale del stock.
    public function estado(): string
    {
        return $this->stock === 0 ? 'EN_FALTA' : 'DISPONIBLE';
    }

    // UML: stockBajo(). 10 o menos (un insumo en falta también tiene stock bajo).
    public function stockBajo(): bool
    {
        return $this->stock <= self::LIMITE_STOCK_BAJO;
    }

    // UML: reponer(cantidad). Carga de stock.
    public function reponer(int $cantidad): void
    {
        $this->stock += $cantidad;
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
            'stock' => $this->stock,
            'costo' => $this->costo,
            'estado' => $this->estado(),
            'stock_bajo' => $this->stockBajo(),
            'activo' => $this->activo
        ];
    }
}
