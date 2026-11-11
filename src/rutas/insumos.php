<?php

// Rutas de insumos: solo para el veterinario (uso interno, no van en /api/tienda/).
// Contrato en docs/api/contrato-insumos.md.
// index.php carga todos los archivos de src/rutas/: cada uno agrega sus rutas al mismo $router.
// Las clases (InsumoControlador, etc.) las carga solo el autoload de index.php.

$router->get('/api/inventario/insumos', [InsumoControlador::class, 'listado']);
$router->post('/api/inventario/insumos', [InsumoControlador::class, 'crear']);

// Va ANTES de las rutas con {id}: el router prueba las rutas en orden, y si /{id} estuviera
// primero, tomaría la palabra "movimientos" como si fuera un id (y respondería 404).
$router->get('/api/inventario/insumos/movimientos', [InsumoControlador::class, 'todosLosMovimientos']);

$router->get('/api/inventario/insumos/{id}', [InsumoControlador::class, 'detalle']);
$router->put('/api/inventario/insumos/{id}', [InsumoControlador::class, 'editar']);
$router->delete('/api/inventario/insumos/{id}', [InsumoControlador::class, 'darDeBaja']);
$router->post('/api/inventario/insumos/{id}/reactivar', [InsumoControlador::class, 'reactivar']);
$router->post('/api/inventario/insumos/{id}/pedido', [InsumoControlador::class, 'marcarPedido']);
$router->delete('/api/inventario/insumos/{id}/pedido', [InsumoControlador::class, 'desmarcarPedido']);
$router->post('/api/inventario/insumos/{id}/stock', [InsumoControlador::class, 'cargarStock']);
$router->post('/api/inventario/insumos/{id}/retiro', [InsumoControlador::class, 'retirar']);
$router->get('/api/inventario/insumos/{id}/movimientos', [InsumoControlador::class, 'movimientos']);
