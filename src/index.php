<?php

// Punto de entrada del módulo Inventario: el Gateway ejecuta este archivo con cada pedido
// de /api/inventario/... y /api/tienda/...

// 1. Cargar las herramientas compartidas (comun/ de ApiGateway).
$raiz = dirname(__DIR__, 2);

require_once $raiz . '/comun/config.php';
require_once $raiz . '/comun/response.php';
require_once $raiz . '/comun/request.php';
require_once $raiz . '/comun/router.php';
require_once $raiz . '/comun/logger.php';
require_once $raiz . '/comun/database.php';
require_once $raiz . '/comun/auth.php';

// 2. Cargar las clases propias de Inventario con un autoload: cuando el código usa una clase
//    que PHP todavía no conoce (por ejemplo ProductoControlador), busca src/ProductoControlador.php
//    y lo carga. Así, al agregar una clase nueva alcanza con crear su archivo: no hay que anotarla
//    en ningún lado. Regla: cada clase va en un archivo con su mismo nombre.
spl_autoload_register(function (string $clase): void {
    $archivo = __DIR__ . '/' . $clase . '.php';

    if (is_file($archivo)) {
        require $archivo;
    }
});

// Las fechas (por ejemplo, "el vencimiento no puede ser anterior a hoy") se calculan con la hora de Argentina.
date_default_timezone_set('America/Argentina/Buenos_Aires');

// 3. Crear el router y cargar las rutas. Cada tema tiene su archivo en src/rutas/
//    (productos.php, insumos.php, interno.php...), y todos agregan sus rutas a este mismo $router.
//    Para sumar rutas nuevas se crea un archivo en src/rutas/: este index.php no se toca.
$router = new Router();

foreach (glob(__DIR__ . '/rutas/*.php') as $archivoDeRutas) {
    require $archivoDeRutas;
}

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
