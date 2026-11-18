<?php

// Los endpoints del pago con Mercado Pago (contrato: docs/api/contrato-tienda.md, "Pago").
//
// El flujo: "Ir a pagar" crea la compra (15 minutos) y pide el pago (pagar): el comprador va a
// Mercado Pago, paga y vuelve a tienda/compra.html. La compra pasa a pagada cuando NUESTRO servidor le
// pregunta a Mercado Pago y confirma el pago, por dos caminos (el que llegue primero; el otro no
// repite nada porque el id del pago es único):
//   - confirmar: la página de la compra, al volver, manda el número de pago que le dio Mercado Pago;
//   - aviso: Mercado Pago avisa por su cuenta (webhook, con firma).
// En local y en GitHub no hay Mercado Pago: simulado aprueba o rechaza el pago como lo haría él.
class PagoControlador
{
    private const ROLES_COMPRADOR = ['CLIENTE', 'RESCATISTA'];
    private const NO_ENCONTRADA = 'Compra no encontrada';

    // POST /api/tienda/compras/{numero}/pago   -> { "url": "<a dónde va el comprador a pagar>" }
    public static function pagar(array $parametros): void
    {
        $comprador = self::comprador();
        $pagos = self::repositorio();
        $compra = $pagos->paraPagar((string) ($parametros['numero'] ?? ''), (int) $comprador['id']);

        if ($compra === null) {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
            return;
        }
        if ($compra['estado'] !== 'PENDIENTE_PAGO') {
            Response::json(['error' => 'La compra no está esperando el pago'], 409);
            return;
        }

        if (MercadoPago::simulado()) {
            Response::json(['url' => '/tienda/pago-simulado.html?numero=' . rawurlencode($compra['numero'])]);
            return;
        }
        if (!MercadoPago::configurado()) {
            Logger::error('Inventario: falta MP_ACCESS_TOKEN o SITIO_URL para cobrar con Mercado Pago');
            Response::json(['error' => 'El pago con Mercado Pago no está disponible en este momento'], 503);
            return;
        }

        $url = MercadoPago::crearPreferencia($compra, $compra['items'], Compra::pagarAntesDe(new DateTimeImmutable($compra['fecha'])));
        if ($url === null) {
            Response::json(['error' => 'No se pudo conectar con Mercado Pago. Probá de nuevo en un momento.'], 502);
            return;
        }

        Response::json(['url' => $url]);
    }

    // POST /api/tienda/compras/{numero}/pago/confirmar   { "payment_id": "123456789" }
    // Al volver de Mercado Pago: se consulta ese pago y se registra. Devuelve la compra actualizada.
    public static function confirmar(array $parametros): void
    {
        $comprador = self::comprador();
        $pagos = self::repositorio();
        $numero = (string) ($parametros['numero'] ?? '');

        $pagoId = Request::json()['payment_id'] ?? null;
        $pagoId = is_int($pagoId) ? (string) $pagoId : $pagoId;

        if (!MercadoPago::simulado() && is_string($pagoId) && ctype_digit($pagoId)) {
            $pago = MercadoPago::consultarPago($pagoId);
            if ($pago === null) {
                Response::json(['error' => 'No se pudo consultar el pago a Mercado Pago. Probá de nuevo en un momento.'], 502);
                return;
            }
            if ($pago === false || $pago['referencia'] !== $numero || self::procesar($pagos, $pago, (int) $comprador['id']) === 'no_existe') {
                Response::json(['error' => self::NO_ENCONTRADA], 404);
                return;
            }
        }

        $compra = $pagos->detalle($numero, (int) $comprador['id']);
        if ($compra === null) {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
            return;
        }

        Response::json($compra);
    }

    // POST /api/tienda/pagos/aviso   (Mercado Pago: ?type=payment&data.id=123, header x-signature)
    public static function aviso(): void
    {
        $cuerpo = Request::json();
        // PHP cambia el punto de "data.id" por "_" al leer la dirección.
        $dataId = (string) ($_GET['data_id'] ?? ($cuerpo['data']['id'] ?? ''));
        $tipo = (string) ($_GET['type'] ?? $_GET['topic'] ?? $cuerpo['type'] ?? '');

        $firmaOk = MercadoPago::firmaValida(
            (string) ($_SERVER['HTTP_X_SIGNATURE'] ?? ''),
            (string) ($_SERVER['HTTP_X_REQUEST_ID'] ?? ''),
            $dataId,
            (string) Config::get('MP_WEBHOOK_SECRET', '')
        );
        if (!$firmaOk) {
            Logger::info('Aviso de pago rechazado: firma de Mercado Pago inválida');
            Response::error('No autorizado', 401);
            return;
        }

        // Solo interesan los pagos (Mercado Pago también avisa de otras cosas).
        if ($tipo !== 'payment') {
            Response::json(['resultado' => 'ignorado']);
            return;
        }

        $pago = MercadoPago::consultarPago($dataId);
        if ($pago === false) {
            // Mercado Pago no conoce ese pago (el aviso de prueba de "Simular notificación"): 200, así no reintenta.
            Response::json(['resultado' => 'no_existe']);
            return;
        }
        if ($pago === null) {
            // 500: Mercado Pago vuelve a mandar el aviso más tarde.
            Response::error('No se pudo consultar el pago', 500);
            return;
        }

        Response::json(['resultado' => self::procesar(self::repositorio(), $pago, null)]);
    }

    // POST /api/tienda/pagos/simulado   { "numero": "V-000123", "resultado": "aprobado" | "rechazado" }
    // Solo en local y en GitHub (ENTORNO local o ci): hace lo mismo que un pago de Mercado Pago.
    // Sirve también para probar un pago tardío (con la compra ya cancelada).
    public static function simulado(): void
    {
        if (!MercadoPago::simulado()) {
            Response::json(['error' => 'No encontrado'], 404);
            return;
        }

        $comprador = self::comprador();
        $datos = Request::json();
        $numero = (string) ($datos['numero'] ?? '');
        $resultado = $datos['resultado'] ?? null;

        if (!in_array($resultado, ['aprobado', 'rechazado'], true)) {
            Response::json(['error' => 'Datos inválidos', 'campos' => ['resultado' => 'Tiene que ser "aprobado" o "rechazado"']], 400);
            return;
        }

        $pagos = self::repositorio();
        $compra = $pagos->paraPagar($numero, (int) $comprador['id']);
        if ($compra === null) {
            Response::json(['error' => self::NO_ENCONTRADA], 404);
            return;
        }

        $registro = self::procesar($pagos, [
            'id' => 'simulado-' . bin2hex(random_bytes(8)),
            'estado' => $resultado === 'aprobado' ? 'approved' : 'rejected',
            'referencia' => $numero,
            'monto' => (float) $compra['total'],
        ], (int) $comprador['id']);

        Response::json(['resultado' => $registro] + $pagos->detalle($numero));
    }

    // ---------- Ayudas ----------

    // Registra el pago y hace lo que sigue: devolver el importe si no se pudo aplicar, y avisarle al
    // comprador si fue un pago tardío. Devuelve lo que devolvió PagoRepositorio::registrar.
    private static function procesar(PagoRepositorio $pagos, array $pago, ?int $compradorId): string
    {
        $resultado = $pagos->registrar($pago, $compradorId);
        $ahora = new DateTimeImmutable();

        if ($resultado === 'devolver' || $resultado === 'revivido') {
            if ($resultado === 'devolver' && !MercadoPago::simulado()) {
                MercadoPago::devolver($pago['id']);
            }

            $comprador = $pagos->comprador($pago['referencia']);
            if ($comprador !== null) {
                $mensaje = $resultado === 'devolver'
                    ? Compra::mensajePagoDevuelto($comprador['nombre'], $pago['referencia'])
                    : Compra::mensajePagoTardioAplicado($comprador['nombre'], $pago['referencia'], new DateTimeImmutable($comprador['fecha']));
                Avisos::mandar((int) $comprador['cliente_id'], $mensaje, "compra-{$pago['referencia']}-pago-{$pago['id']}", $ahora);
            }
        }

        return $resultado;
    }

    // Exige sesión de comprador y el token CSRF, y antes cancela las compras vencidas ("al pasar").
    private static function comprador(): array
    {
        $sesion = Auth::requerirRol(self::ROLES_COMPRADOR);
        Auth::verificarCsrf($sesion);
        Vencimientos::alPasar();

        return $sesion;
    }

    private static function repositorio(): PagoRepositorio
    {
        return new PagoRepositorio(Database::conectar());
    }
}
