<?php

// Valida los datos del alta y la edición de un producto (reglas y mensajes del contrato, docs/api/contrato.md).
// Devuelve [datos limpios, errores]: si errores está vacío, los datos limpios están listos para guardar.
class ValidadorProducto
{
    public const CATEGORIAS = ['MEDICAMENTO', 'ALIMENTO', 'ACCESORIO'];
    public const ESPECIES = ['PERRO', 'GATO', 'TODAS'];

    public const PRECIO_MAXIMO = 100000000;   // DECIMAL(10,2) llega hasta 99.999.999,99
    public const STOCK_MAXIMO = 1000000;
    public const CANTIDAD_MAXIMA = 100000;    // por carga de stock

    // Alta: incluye el stock inicial y el vencimiento no puede ser anterior a hoy.
    public static function alta(array $datos): array
    {
        return self::validar($datos, true);
    }

    // Edición: sin stock (se ignora si llega) y se permite un vencimiento ya pasado.
    public static function edicion(array $datos): array
    {
        return self::validar($datos, false);
    }

    // Carga de stock: devuelve la cantidad, o null si no es válida.
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

        // nombre
        $nombre = self::texto($datos, 'nombre');
        if (self::falta($datos, 'nombre')) {
            $errores['nombre'] = 'El nombre es obligatorio';
        } elseif ($nombre === null || mb_strlen($nombre, 'UTF-8') < 2 || mb_strlen($nombre, 'UTF-8') > 120) {
            $errores['nombre'] = 'El nombre debe tener entre 2 y 120 caracteres';
        } else {
            $limpios['nombre'] = $nombre;
        }

        // descripcion (opcional; vacía se guarda como null)
        $valor = $datos['descripcion'] ?? null;
        if ($valor !== null && !is_string($valor)) {
            $errores['descripcion'] = 'La descripción puede tener hasta 2000 caracteres';
        } else {
            $descripcion = $valor === null ? '' : trim($valor);
            if (mb_strlen($descripcion, 'UTF-8') > 2000) {
                $errores['descripcion'] = 'La descripción puede tener hasta 2000 caracteres';
            } else {
                $limpios['descripcion'] = $descripcion === '' ? null : $descripcion;
            }
        }

        // categoria y especie: tienen que ser uno de los códigos
        foreach ([
            'categoria' => [self::CATEGORIAS, 'La categoría es obligatoria', 'La categoría no es válida'],
            'especie' => [self::ESPECIES, 'La especie es obligatoria', 'La especie no es válida'],
        ] as $campo => [$validos, $siFalta, $siInvalido]) {
            $valor = self::texto($datos, $campo);
            if (self::falta($datos, $campo)) {
                $errores[$campo] = $siFalta;
            } elseif (!in_array($valor, $validos, true)) {
                $errores[$campo] = $siInvalido;
            } else {
                $limpios[$campo] = $valor;
            }
        }

        // precio: número (no texto) mayor que 0, con hasta 2 decimales
        $precio = $datos['precio'] ?? null;
        if ($precio === null || $precio === '') {
            $errores['precio'] = 'El precio es obligatorio';
        } elseif (
            !(is_int($precio) || is_float($precio))
            || !is_finite((float) $precio)
            || $precio <= 0
            || $precio >= self::PRECIO_MAXIMO
            || abs($precio * 100 - round($precio * 100)) > 0.000001
        ) {
            $errores['precio'] = 'El precio debe ser un número mayor que 0 con hasta 2 decimales';
        } else {
            $limpios['precio'] = round((float) $precio, 2);
        }

        // stock: solo en el alta
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

        // vencimiento (opcional): AAAA-MM-DD; en el alta, de hoy en adelante
        $vencimiento = $datos['vencimiento'] ?? null;
        if ($vencimiento === null || $vencimiento === '') {
            $limpios['vencimiento'] = null;
        } elseif (!is_string($vencimiento) || !self::esFecha($vencimiento)) {
            $errores['vencimiento'] = 'La fecha de vencimiento no es válida';
        } elseif ($esAlta && $vencimiento < date('Y-m-d')) {
            // Las fechas AAAA-MM-DD se pueden comparar como texto.
            $errores['vencimiento'] = 'La fecha de vencimiento no puede ser anterior a hoy';
        } else {
            $limpios['vencimiento'] = $vencimiento;
        }

        // imagen (opcional). En la edición, si no llega se conserva la actual: por eso la clave
        // "imagen" solo aparece en los datos limpios cuando vino en el pedido.
        if (array_key_exists('imagen', $datos)) {
            if ($datos['imagen'] === null) {
                $limpios['imagen'] = null;   // borrar la imagen
            } else {
                $imagen = Imagen::desdeDataUrl($datos['imagen']);
                if (is_string($imagen)) {
                    $errores['imagen'] = $imagen;
                } else {
                    $limpios['imagen'] = $imagen;   // [bytes, tipo]
                }
            }
        }

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

    // ¿Es una fecha real con formato AAAA-MM-DD? (rechaza, por ejemplo, 2026-02-30)
    private static function esFecha(string $valor): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $partes)) {
            return false;
        }

        return checkdate((int) $partes[2], (int) $partes[3], (int) $partes[1]);
    }
}
