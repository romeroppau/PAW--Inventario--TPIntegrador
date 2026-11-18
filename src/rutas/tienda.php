<?php

// Rutas de la compra en la tienda: el carrito, las compras, el retiro y los vencimientos.
// Contrato en docs/api/contrato-tienda.md.
// index.php carga todos los archivos de src/rutas/: cada uno agrega sus rutas al mismo $router.
// Las clases (CarritoControlador, etc.) las carga solo el autoload de index.php.

// Carrito (comprador: cliente o rescatista).
$router->get('/api/tienda/carrito', [CarritoControlador::class, 'ver']);
$router->post('/api/tienda/carrito/items', [CarritoControlador::class, 'agregar']);
$router->put('/api/tienda/carrito/items/{producto_id}', [CarritoControlador::class, 'cambiar']);
$router->delete('/api/tienda/carrito/items/{producto_id}', [CarritoControlador::class, 'quitar']);

// Compras (comprador).
$router->post('/api/tienda/carrito/confirmar', [CompraControlador::class, 'confirmar']);
$router->get('/api/tienda/compras', [CompraControlador::class, 'misCompras']);
$router->get('/api/tienda/compras/{numero}', [CompraControlador::class, 'ver']);
$router->post('/api/tienda/compras/{numero}/cancelar', [CompraControlador::class, 'cancelar']);

// Retiro en el local (veterinaria).
$router->get('/api/inventario/compras', [CompraControlador::class, 'deLaVeterinaria']);
$router->post('/api/tienda/compras/{numero}/entregada', [CompraControlador::class, 'entregada']);

// Tarea programada (GitHub Actions, con X-Tarea-Token): vencimientos y recordatorios.
$router->post('/api/tienda/tareas/vencer', [TareaControlador::class, 'vencer']);

// Pago con Mercado Pago (comprador) y su aviso (Mercado Pago). En local y GitHub, el pago simulado.
$router->post('/api/tienda/compras/{numero}/pago', [PagoControlador::class, 'pagar']);
$router->post('/api/tienda/compras/{numero}/pago/confirmar', [PagoControlador::class, 'confirmar']);
$router->post('/api/tienda/pagos/aviso', [PagoControlador::class, 'aviso']);
$router->post('/api/tienda/pagos/simulado', [PagoControlador::class, 'simulado']);
