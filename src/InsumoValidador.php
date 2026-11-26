<?php

// Valida los datos del alta y la edición de un insumo (reglas y mensajes del contrato,
// docs/api/contrato-insumos.md, "Validación de campos").
// Devuelve [datos limpios, errores]: si errores está vacío, los datos limpios están listos para guardar.
class InsumoValidador
{
    public const COSTO_MAXIMO = 100000000;    // DECIMAL(10,2) llega hasta 99.999.999,99
    public const STOCK_MAXIMO = 1000000;
    public const CANTIDAD_MAXIMA = 100000;    // por carga o retiro de stock

    // Alta: nombre, costo, stock inicial, si vence y el primer lote (vencimiento y número del fabricante).
    public static function alta(array $datos): array
    {
        return self::validar($datos, true);
    }

    // Edición: nombre, costo y (si vienen) la aplicación y "vence". El stock y los lotes no se editan
    // acá (si llegan, se ignoran).
    public static function edicion(array $datos): array
    {
        return self::validar($datos, false);
    }

    // Carga y retiro de stock: devuelve la cantidad, o null si no es válida.
    public static function cantidad(mixed $valor): ?int
    {
        if (!is_int($valor) || $valor < 1 || $valor > self::CANTIDAD_MAXIMA) {
            return null;
        }

        return $valor;
    }

    private static function validar(array $datos, bool $esAlta): array
    {
        $limpios = [];
        $errores = [];

        // nombre: texto de 2 a 120 caracteres
        $nombre = self::texto($datos, 'nombre');
        if (self::falta($datos, 'nombre')) {
            $errores['nombre'] = 'El nombre es obligatorio';
        } elseif ($nombre === null || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 120) {
            $errores['nombre'] = 'El nombre debe tener entre 2 y 120 caracteres';
        } else {
            $limpios['nombre'] = $nombre;
        }

        // costo: número (no texto) mayor o igual a 0, con hasta 2 decimales
        $costo = $datos['costo'] ?? null;
        if ($costo === null || $costo === '') {
            $errores['costo'] = 'El costo es obligatorio';
        } elseif (
            !(is_int($costo) || is_float($costo))
            || !is_finite((float) $costo)
            || $costo < 0
            || $costo >= self::COSTO_MAXIMO
            || abs($costo * 100 - round($costo * 100)) > 0.000001
        ) {
            $errores['costo'] = 'El costo debe ser un número mayor o igual a 0 con hasta 2 decimales';
        } else {
            $limpios['costo'] = round((float) $costo, 2);
        }

        // stock: solo en el alta, entero de 0 a 1.000.000
        if ($esAlta) {
            $stock = $datos['stock'] ?? null;
            if ($stock === null || $stock === '') {
                $errores['stock'] = 'El stock es obligatorio';
            } elseif (!is_int($stock) || $stock < 0 || $stock > self::STOCK_MAXIMO) {
                $errores['stock'] = 'El stock debe ser un número entero entre 0 y 1.000.000';
            } else {
                $limpios['stock'] = $stock;
            }
        }

        // aplicacion (migración 025): opcional. null o "" = no es del calendario. Si no viene en la
        // edición, no se cambia.
        if (array_key_exists('aplicacion', $datos)) {
            $aplicacion = $datos['aplicacion'];
            if ($aplicacion === null || $aplicacion === '') {
                $limpios['aplicacion'] = null;
            } elseif (is_string($aplicacion) && in_array($aplicacion, Insumo::APLICACIONES, true)) {
                $limpios['aplicacion'] = $aplicacion;
            } else {
                $errores['aplicacion'] = 'Elegí una vacuna o antiparasitario de la lista';
            }
        }

        // vence (migración 029): obligatorio en el alta; en la edición, si no viene, no se cambia
        // (InsumoControlador controla que el stock esté en 0).
        [$vence, $errorVence] = Lotes::validarVence($datos, $esAlta);
        if ($errorVence !== null) {
            $errores['vence'] = $errorVence;
        } elseif ($vence !== null) {
            $limpios['vence'] = $vence;
        }

        // Solo en el alta: el primer lote (L1), con su vencimiento (de hoy en adelante; obligatorio si
        // vence y tiene stock inicial, y vacío si no vence) y el número del fabricante ("lote", opcional).
        if ($esAlta) {
            $conStock = is_int($datos['stock'] ?? null) && $datos['stock'] > 0;
            [$lote, $erroresLote] = Lotes::validarNuevo(
                ['codigo' => $datos['lote'] ?? null, 'vencimiento' => $datos['vencimiento'] ?? null],
                date('Y-m-d'),
                $vence === false ? false : ($vence === true && $conStock ? true : null),
                'insumo'
            );
            if (isset($erroresLote['codigo'])) {
                $errores['lote'] = $erroresLote['codigo'];
            } else {
                $limpios['lote'] = $lote['codigo'];
            }
            if (isset($erroresLote['vencimiento'])) {
                $errores['vencimiento'] = $erroresLote['vencimiento'];
            } else {
                $limpios['vencimiento'] = $lote['vencimiento'];
            }
        }

        // Cualquier otro campo (id, estado, stock_bajo, activo...) se ignora: no se copia a $limpios.

        return [$limpios, $errores];
    }

    // ¿El campo no vino, es null o es un texto vacío (o solo con espacios)?
    // Un valor de otro tipo (un número, una lista) no "falta": es inválido.
    private static function falta(array $datos, string $campo): bool
    {
        $valor = $datos[$campo] ?? null;

        return $valor === null || (is_string($valor) && trim($valor) === '');
    }

    // Devuelve el campo sin espacios al principio y al final, o null si no vino o no es texto.
    private static function texto(array $datos, string $campo): ?string
    {
        $valor = $datos[$campo] ?? null;

        return is_string($valor) ? trim($valor) : null;
    }
}
