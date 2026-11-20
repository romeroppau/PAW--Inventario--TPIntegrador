<?php

// Las reglas de una promoción (tabla promocion, migración 016). Contrato: docs/api/contrato-tienda.md,
// sección "Promociones". No usa la base: el SQL está en PromocionRepositorio.
// - El descuento es un porcentaje entero entre 5 y 90.
// - Dura hasta el día "hasta" inclusive: desde hoy y como mucho 90 días, y nunca después del
//   vencimiento del producto (vencido, el producto ya no se vende).
// - El precio con descuento se calcula siempre con el precio del producto de ese momento.
class Promocion
{
    public const PORCENTAJE_MINIMO = 5;
    public const PORCENTAJE_MAXIMO = 90;
    public const DIAS_MAXIMOS = 90;

    // Para las consultas que traen productos: la promoción vigente del producto "p" (a lo sumo una,
    // por el índice único de la tabla) y el precio final, con o sin descuento. La cuenta se hace en
    // MySQL (con DECIMAL, sin errores de redondeo) y es la misma en el catálogo, el carrito y la compra.
    public const JOIN_VIGENTE = 'LEFT JOIN promocion pr ON pr.producto_id = p.id AND pr.terminada_en IS NULL AND pr.hasta >= CURDATE()';
    public const PRECIO_FINAL = 'ROUND(p.precio * (100 - COALESCE(pr.porcentaje, 0)) / 100, 2)';

    // Valida el pedido de "Publicitar" ({porcentaje, hasta}) para un producto que vence en
    // $vencimiento (o null si no vence). Devuelve [datos limpios, errores por campo].
    public static function validar(mixed $datos, DateTimeImmutable $hoy, ?string $vencimiento): array
    {
        $datos = is_array($datos) ? $datos : [];
        $errores = [];
        $limpios = [];

        $porcentaje = $datos['porcentaje'] ?? null;
        if (is_string($porcentaje) && ctype_digit($porcentaje)) {
            $porcentaje = (int) $porcentaje;
        }
        if (!is_int($porcentaje) || $porcentaje < self::PORCENTAJE_MINIMO || $porcentaje > self::PORCENTAJE_MAXIMO) {
            $errores['porcentaje'] = 'El descuento tiene que ser un número entero entre ' . self::PORCENTAJE_MINIMO
                . ' y ' . self::PORCENTAJE_MAXIMO . ' (por ciento)';
        } else {
            $limpios['porcentaje'] = $porcentaje;
        }

        $hasta = self::fecha($datos['hasta'] ?? null);
        $hoy = $hoy->setTime(0, 0);
        $maximo = $hoy->modify('+' . self::DIAS_MAXIMOS . ' days');
        if ($hasta === null) {
            $errores['hasta'] = 'Elegí hasta qué día dura la promoción';
        } elseif ($hasta < $hoy) {
            $errores['hasta'] = 'La promoción no puede terminar antes de hoy';
        } elseif ($hasta > $maximo) {
            $errores['hasta'] = 'La promoción puede durar como mucho ' . self::DIAS_MAXIMOS . ' días (hasta el '
                . $maximo->format('d/m/Y') . ')';
        } elseif ($vencimiento !== null && $hasta->format('Y-m-d') > $vencimiento) {
            $errores['hasta'] = 'El producto vence el ' . self::texto($vencimiento)
                . ': la promoción no puede durar más que eso';
        } else {
            $limpios['hasta'] = $hasta->format('Y-m-d');
        }

        return [$limpios, $errores];
    }

    // "2026-12-10" → "10/12/2026"
    public static function texto(string $fecha): string
    {
        return implode('/', array_reverse(explode('-', substr($fecha, 0, 10))));
    }

    // Una fecha YYYY-MM-DD que existe (no 2026-02-30), o null.
    private static function fecha(mixed $valor): ?DateTimeImmutable
    {
        if (!is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor) !== 1) {
            return null;
        }
        $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new DateTimeZone('America/Argentina/Buenos_Aires'));

        return $fecha !== false && $fecha->format('Y-m-d') === $valor ? $fecha : null;
    }
}
