<?php

// La foto de un producto sola, como imagen (no dentro de un JSON): para usarla directo en
// <img src="/api/tienda/productos/101/imagen">. La usan el catálogo y el carrito (contrato-tienda.md).
// El navegador la guarda, pero antes de usarla pregunta si cambió (Cache-Control: no-cache + ETag):
// si es la misma, se responde 304 sin la foto; si la veterinaria la cambió, se manda la nueva.
// Es pública, como el catálogo. Responde aunque el producto ya no esté a la venta: el carrito
// puede tener uno que se dio de baja después de agregarlo.
class ImagenProductoControlador
{
    // GET /api/tienda/productos/{id}/imagen
    public static function ver(array $parametros): void
    {
        $id = (string) ($parametros['id'] ?? '');
        if (!ctype_digit($id) || strlen($id) > 10) {
            Response::json(['error' => 'Imagen no encontrada'], 404);
            return;
        }

        $consulta = Database::conectar()->prepare('SELECT imagen, imagen_tipo FROM producto WHERE id = ? AND imagen IS NOT NULL AND LENGTH(imagen) > 0');
        $consulta->execute([(int) $id]);
        $fila = $consulta->fetch();

        if ($fila === false || !in_array($fila['imagen_tipo'], Imagen::TIPOS, true)) {
            Response::json(['error' => 'Imagen no encontrada'], 404);
            return;
        }

        // La "huella" de la foto: si el navegador ya tiene esta misma, no se la vuelve a mandar.
        $etiqueta = '"' . md5($fila['imagen']) . '"';
        header('Cache-Control: no-cache');
        header('ETag: ' . $etiqueta);
        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etiqueta) {
            http_response_code(304);
            return;
        }

        header('Content-Type: ' . $fila['imagen_tipo']);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($fila['imagen']));
        echo $fila['imagen'];
    }
}
