<?php

// De dónde salió un movimiento de inventario, a partir de su id_operacion: la consulta en la que se usó
// el insumo o el producto, o la compra de la tienda. Así la veterinaria puede ir a la consulta o a la
// factura desde los movimientos. No usa la base de datos.
//   - Consulta (Pacientes, ConsultaControlador::finalizar): "consulta-{id}-insumo-{ítem}" o "consulta-{id}-producto-{ítem}".
//   - Compra (CompraRepositorio): "venta-V-000123-{producto}", "cancela-V-000123-{producto}" (también con
//     prefijo, como los de las semillas: "semilla-venta-V-000101-101", "prod-venta-V-030002-3001").
//   - Desde la migración 028 un movimiento es de un lote: los que siguen al primero terminan en
//     "-lote-{lote}" (y el que reserva de nuevo una compra pagada tarde empieza con "revive-").
class ReferenciaMovimiento
{
    // ['consulta_id' => int|null, 'compra' => "V-000123"|null]
    public static function de(?string $idOperacion): array
    {
        $consulta = null;
        $compra = null;

        if ($idOperacion !== null) {
            if (preg_match('/(?:^|-)consulta-(\d+)-(?:insumo|producto)-\d+(?:-lote-\d+)?$/', $idOperacion, $m) === 1) {
                $consulta = (int) $m[1];
            } elseif (preg_match('/(?:^|-)(?:venta|cancela|revive)-(V-\d{6})-\d+(?:-lote-\d+)?(?:-\d+)?$/', $idOperacion, $m) === 1) {
                $compra = $m[1];
            }
        }

        return ['consulta_id' => $consulta, 'compra' => $compra];
    }

    // Valida el filtro de fechas de los movimientos (?desde=&hasta=, AAAA-MM-DD, los dos opcionales).
    // Devuelve [desde, hasta, errores por campo].
    public static function fechas(mixed $desde, mixed $hasta): array
    {
        $errores = [];
        $limpias = [];
        foreach (['desde' => $desde, 'hasta' => $hasta] as $campo => $valor) {
            if ($valor === null || $valor === '') {
                $limpias[$campo] = null;
                continue;
            }
            $fecha = is_string($valor) ? DateTimeImmutable::createFromFormat('!Y-m-d', $valor) : false;
            if ($fecha === false || $fecha->format('Y-m-d') !== $valor) {
                $errores[$campo] = 'La fecha tiene que tener el formato AAAA-MM-DD';
                $limpias[$campo] = null;
            } else {
                $limpias[$campo] = $valor;
            }
        }
        if ($errores === [] && $limpias['desde'] !== null && $limpias['hasta'] !== null && $limpias['desde'] > $limpias['hasta']) {
            $errores['desde'] = 'La fecha "desde" no puede ser posterior a "hasta"';
        }

        return [$limpias['desde'], $limpias['hasta'], $errores];
    }
}
