<?php

// Rutas de productos: la tienda (pública) y el panel del veterinario.
// Contrato en docs/api/contrato.md.
// index.php carga todos los archivos de src/rutas/: cada uno agrega sus rutas al mismo $router.
// Las clases (ProductoControlador, etc.) las carga solo el autoload de index.php.

// Tienda: públicas.
$router->get('/api/tienda/productos', [ProductoControlador::class, 'catalogo']);
$router->get('/api/tienda/productos/{id}', [ProductoControlador::class, 'detalleTienda']);

// Panel del veterinario.
$router->get('/api/inventario/productos', [ProductoControlador::class, 'listado']);
$router->post('/api/inventario/productos', [ProductoControlador::class, 'crear']);
$router->get('/api/inventario/productos/{id}', [ProductoControlador::class, 'detalle']);
$router->put('/api/inventario/productos/{id}', [ProductoControlador::class, 'editar']);
$router->delete('/api/inventario/productos/{id}', [ProductoControlador::class, 'darDeBaja']);
$router->post('/api/inventario/productos/{id}/reactivar', [ProductoControlador::class, 'reactivar']);
$router->post('/api/inventario/productos/{id}/stock', [ProductoControlador::class, 'cargarStock']);
$router->get('/api/inventario/productos/{id}/movimientos', [ProductoControlador::class, 'movimientos']);

// Salud del módulo.
$router->get('/api/inventario/salud', [ProductoControlador::class, 'salud']);
