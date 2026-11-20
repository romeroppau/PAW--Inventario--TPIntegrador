<?php

// La foto de un producto sola, como imagen (no dentro de un JSON): para usarla directo en
// <img src="/api/tienda/productos/101/imagen">. La usa el carrito (contrato-tienda.md, "Carrito").
// El navegador la guarda una hora (Cache-Control), así no la baja cada vez que se mira el carrito.
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

        $consulta = Database::conectar()->prepare('SELECT imagen, imagen_tipo FROM producto WHERE id = ? AND imagen IS NOT NULL');
        $consulta->execute([(int) $id]);
        $fila = $consulta->fetch();

        if ($fila === false || !in_array($fila['imagen_tipo'], Imagen::TIPOS, true)) {
            Response::json(['error' => 'Imagen no encontrada'], 404);
            return;
        }

        header('Content-Type: ' . $fila['imagen_tipo']);
        header('Cache-Control: public, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($fila['imagen']));
        echo $fila['imagen'];
    }
}
