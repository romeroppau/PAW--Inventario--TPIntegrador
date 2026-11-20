<?php

// Rutas de las promociones ("Publicitar" desde las sugerencias de Reportes).
// Contrato en docs/api/contrato-tienda.md, sección "Promociones".
// index.php carga todos los archivos de src/rutas/: cada uno agrega sus rutas al mismo $router.

// Tienda: pública.
$router->get('/api/tienda/promociones', [PromocionControlador::class, 'tienda']);

// Panel de la veterinaria.
$router->get('/api/inventario/promociones', [PromocionControlador::class, 'listado']);
$router->post('/api/inventario/promociones', [PromocionControlador::class, 'crear']);
$router->delete('/api/inventario/promociones/{id}', [PromocionControlador::class, 'terminar']);
