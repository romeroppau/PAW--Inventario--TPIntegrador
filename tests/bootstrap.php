<?php
// tests/bootstrap.php
require_once __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(function (string $clase): void {
    $archivo = __DIR__ . '/../src/' . $clase . '.php';

    if (is_file($archivo)) {
        require $archivo;
    }
});