<?php

// Habla con Mercado Pago (Checkout Pro, API de preferencias). Contrato: docs/api/contrato-tienda.md, "Pago".
//
// Configuración (variables de entorno, solo las recibe Inventario):
//   MP_ACCESS_TOKEN    Access Token del VENDEDOR DE PRUEBA. Siempre credenciales de prueba: nunca se cobra de verdad.
//   MP_WEBHOOK_SECRET  Clave secreta del webhook (panel de Mercado Pago, "Webhooks"): firma de los avisos.
//   SITIO_URL          Dirección pública del sitio (la de Vercel), para la vuelta del comprador y el aviso.
//   ENTORNO            local o ci: modo simulado (no se llama a Mercado Pago).
//
// No se cree en lo que dice el aviso ni la vuelta del comprador: el estado de un pago siempre se
// consulta a Mercado Pago con el Access Token (consultarPago).
class MercadoPago
{
    private const API = 'https://api.mercadopago.com';

    // En las pruebas (local y GitHub) no hay Mercado Pago: el pago se simula (PagoControlador::simulado).
    public static function simulado(): bool
    {
        return in_array(Config::get('ENTORNO'), ['local', 'ci'], true);
    }

    // ¿Está todo lo necesario para cobrar con Mercado Pago?
    public static function configurado(): bool
    {
        return self::token() !== '' && self::sitio() !== '';
    }

    public static function sitio(): string
    {
        return rtrim((string) Config::get('SITIO_URL', ''), '/');
    }

    // Crea la preferencia de pago de una compra y devuelve la dirección a la que va el comprador.
    // - Vence junto con la compra (pagar_antes_de): después ya no se puede pagar.
    // - Sin efectivo (tarda días en acreditarse) y en una sola cuota.
    // - binary_mode: el pago queda aprobado o rechazado al instante (nunca "pendiente").
    public static function crearPreferencia(array $compra, array $items, DateTimeImmutable $vence): ?string
    {
        $vuelta = self::sitio() . '/tienda/compra.html?numero=' . rawurlencode($compra['numero']);

        [$codigo, $datos] = self::pedir('POST', '/checkout/preferences', [
            'items' => array_map(fn (array $item) => [
                'id' => (string) $item['producto_id'],
                // Un producto llevado en parte con descuento tiene dos líneas (028).
                'title' => $item['nombre'] . (($item['en_promocion'] ?? false) ? ' (con descuento)' : ''),
                'quantity' => (int) $item['cantidad'],
                'unit_price' => (float) $item['precio_unitario'],
                'currency_id' => 'ARS',
            ], $items),
            'external_reference' => $compra['numero'],
            'statement_descriptor' => 'HUELLAVET',
            'back_urls' => ['success' => $vuelta, 'failure' => $vuelta, 'pending' => $vuelta],
            'auto_return' => 'approved',
            'notification_url' => self::sitio() . '/api/tienda/pagos/aviso',
            'binary_mode' => true,
            'payment_methods' => [
                'excluded_payment_types' => [['id' => 'ticket'], ['id' => 'atm']],
                'installments' => 1,
            ],
            'expires' => true,
            'expiration_date_from' => (new DateTimeImmutable())->format('Y-m-d\TH:i:s.vP'),
            'expiration_date_to' => $vence->format('Y-m-d\TH:i:s.vP'),
        ], 'preferencia-' . $compra['numero'] . '-' . $vence->getTimestamp());

        if ($codigo !== 201 || !is_array($datos)) {
            Logger::error("Inventario: Mercado Pago no creó la preferencia de {$compra['numero']} (HTTP $codigo)");
            return null;
        }

        // Con credenciales TEST- se usa el sandbox; con las de un vendedor de prueba, init_point.
        $url = str_starts_with(self::token(), 'TEST-') ? ($datos['sandbox_init_point'] ?? null) : ($datos['init_point'] ?? null);

        return is_string($url) ? $url : null;
    }

    // Consulta un pago a Mercado Pago. Devuelve ['id', 'estado', 'referencia', 'monto'], false si
    // Mercado Pago dice que ese pago no existe (por ejemplo, el aviso de "Simular notificación"), o null
    // si no se pudo consultar (Mercado Pago no respondió).
    // estado: approved, rejected, cancelled, in_process, pending...
    public static function consultarPago(string $pagoId): array|false|null
    {
        if (!ctype_digit($pagoId)) {
            return false;
        }

        [$codigo, $datos] = self::pedir('GET', '/v1/payments/' . $pagoId);
        if ($codigo === 404) {
            return false;
        }
        if ($codigo !== 200 || !is_array($datos)) {
            Logger::error("Inventario: no se pudo consultar el pago $pagoId a Mercado Pago (HTTP $codigo)");
            return null;
        }

        return [
            'id' => (string) ($datos['id'] ?? $pagoId),
            'estado' => (string) ($datos['status'] ?? ''),
            'referencia' => (string) ($datos['external_reference'] ?? ''),
            'monto' => (float) ($datos['transaction_amount'] ?? 0),
        ];
    }

    // Devuelve todo el importe de un pago. Devuelve true si Mercado Pago lo aceptó.
    public static function devolver(string $pagoId): bool
    {
        if (!ctype_digit($pagoId)) {
            return false;
        }

        [$codigo] = self::pedir('POST', "/v1/payments/$pagoId/refunds", (object) [], "devolucion-$pagoId");
        if ($codigo !== 201 && $codigo !== 200) {
            Logger::error("Inventario: Mercado Pago no devolvió el pago $pagoId (HTTP $codigo). Hay que devolverlo a mano.");
            return false;
        }

        return true;
    }

    // Devuelve una parte de un pago (el reintegro al cancelar un pedido pagado). La clave de
    // idempotencia es fija por pago: si se reintenta, Mercado Pago no devuelve dos veces.
    // Devuelve true si Mercado Pago lo aceptó.
    public static function devolverParte(string $pagoId, float $monto): bool
    {
        if (!ctype_digit($pagoId) || $monto <= 0) {
            return false;
        }

        [$codigo] = self::pedir('POST', "/v1/payments/$pagoId/refunds", ['amount' => round($monto, 2)], "reintegro-$pagoId");
        if ($codigo !== 201 && $codigo !== 200) {
            Logger::error("Inventario: Mercado Pago no hizo el reintegro de \$ $monto del pago $pagoId (HTTP $codigo). Se reintenta en la próxima tarea.");
            return false;
        }

        return true;
    }

    // ¿El aviso viene de Mercado Pago? Se firma con la clave secreta del webhook:
    // x-signature: "ts=1704908010,v1=<hmac>", sobre "id:<data.id>;request-id:<x-request-id>;ts:<ts>;"
    // (si falta un dato, esa parte no va). El data.id alfanumérico va en minúsculas.
    // Es una función pura (recibe todo como datos) para poder probarla: tests/MercadoPagoTest.php.
    public static function firmaValida(string $firma, string $requestId, string $dataId, string $secreto): bool
    {
        if ($secreto === '' || $firma === '') {
            return false;
        }

        $partes = [];
        foreach (explode(',', $firma) as $par) {
            [$clave, $valor] = array_pad(explode('=', trim($par), 2), 2, '');
            $partes[$clave] = $valor;
        }
        $ts = $partes['ts'] ?? '';
        $v1 = $partes['v1'] ?? '';
        if ($ts === '' || $v1 === '') {
            return false;
        }

        $manifiesto = '';
        if ($dataId !== '') {
            $manifiesto .= 'id:' . (ctype_alnum($dataId) ? strtolower($dataId) : $dataId) . ';';
        }
        if ($requestId !== '') {
            $manifiesto .= "request-id:$requestId;";
        }
        $manifiesto .= "ts:$ts;";

        return hash_equals(hash_hmac('sha256', $manifiesto, $secreto), $v1);
    }

    // ---------- Ayudas ----------

    private static function token(): string
    {
        return (string) Config::get('MP_ACCESS_TOKEN', '');
    }

    // Un pedido a la API de Mercado Pago. Devuelve [código HTTP, JSON decodificado] (código 0 si no respondió).
    private static function pedir(string $metodo, string $ruta, array|object|null $cuerpo = null, ?string $idempotencia = null): array
    {
        $cabeceras = "Authorization: Bearer " . self::token() . "\r\nAccept: application/json\r\n";
        if ($cuerpo !== null) {
            $cabeceras .= "Content-Type: application/json\r\n";
        }
        if ($idempotencia !== null) {
            // Si el pedido se repite (reintento), Mercado Pago no lo hace dos veces.
            $cabeceras .= "X-Idempotency-Key: $idempotencia\r\n";
        }

        $contexto = stream_context_create(['http' => [
            'method' => $metodo,
            'header' => $cabeceras,
            'content' => $cuerpo === null ? '' : json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);

        $respuesta = @file_get_contents(self::API . $ruta, false, $contexto);

        $lineas = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        $codigo = preg_match('#^HTTP/\S+\s+(\d{3})#', $lineas[0] ?? '', $coincidencia) ? (int) $coincidencia[1] : 0;

        return [$codigo, is_string($respuesta) ? json_decode($respuesta, true) : null];
    }
}
