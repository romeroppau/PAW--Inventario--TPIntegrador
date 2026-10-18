<?php

// 1. Cargar las herramientas compartidas (comun/ de ApiGateway).
$raiz = dirname(__DIR__, 2);

require_once $raiz . '/comun/config.php';
require_once $raiz . '/comun/response.php';
require_once $raiz . '/comun/request.php';
require_once $raiz . '/comun/router.php';
require_once $raiz . '/comun/logger.php';
require_once $raiz . '/comun/database.php';
require_once $raiz . '/comun/auth.php';

// 2. Cargar las clases propias de Inventario.
require_once __DIR__ . '/Imagen.php';
require_once __DIR__ . '/ValidadorProducto.php';
require_once __DIR__ . '/ProductoRepositorio.php';
require_once __DIR__ . '/ProductoControlador.php';

// Las fechas (por ejemplo, "el vencimiento no puede ser anterior a hoy") se calculan con la hora de Argentina.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 3. Crear el router y registrar las rutas (contrato en docs/api/contrato.md).
$router = new Router();

// Tienda: públicas.
$router->get('/api/tienda/productos', [ProductoControlador::class, 'catalogo']);
$router->get('/api/tienda/productos/{id}', [ProductoControlador::class, 'detalleTienda']);

// Panel del veterinario.
$router->get('/api/inventario/productos', [ProductoControlador::class, 'listado']);
$router->post('/api/inventario/productos', [ProductoControlador::class, 'crear']);
$router->get('/api/inventario/productos/{id}', [ProductoControlador::class, 'detalle']);
$router->put('/api/inventario/productos/{id}', [ProductoControlador::class, 'editar']);
$router->delete('/api/inventario/productos/{id}', [ProductoControlador::class, 'darDeBaja']);
$router->post('/api/inventario/productos/{id}/stock', [ProductoControlador::class, 'cargarStock']);
$router->get('/api/inventario/productos/{id}/movimientos', [ProductoControlador::class, 'movimientos']);

$router->get('/api/inventario/salud', [ProductoControlador::class, 'salud']);

// 4. Ejecutar el router y manejar errores inesperados.
try {
    $router->dispatch(
        Request::method(),
        Request::path()
    );
} catch (Throwable $error) {
    Logger::error(
        'Error inesperado en Inventario: '
        . get_class($error)
        . ' - '
        . $error->getMessage()
    );

    Response::error(
        'Ocurrió un error interno del servidor',
        500
    );
}
