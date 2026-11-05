<?php

// Comunicación interna entre módulos.
$router->post(
    '/internal/stock/descontar',
    [StockInternoControlador::class, 'descontar']
);