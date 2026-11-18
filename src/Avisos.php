<?php

// Manda un aviso al comprador por el buzón de notificaciones de Backend (POST /internal/notificaciones),
// de proceso a proceso, sin pasar por Nginx. Contrato: docs/api/contrato-tienda.md, "Avisos al comprador".
//
// Cada aviso sale por los dos canales: la campana del sitio (WEB) y WhatsApp. Backend no acepta dos
// notificaciones con el mismo id_operacion, así que cada canal lleva el suyo:
// compra-V-000003-dia2-web y compra-V-000003-dia2-whatsapp. Mandarlo de nuevo no lo repite.
class Avisos
{
    private const CANALES = ['web' => 'WEB', 'whatsapp' => 'WHATSAPP'];

    // Devuelve true si el aviso es nuevo, false si ya se había mandado, o null si Backend no lo
    // recibió (falta el token, no respondió o lo rechazó). Los errores quedan en el log.
    public static function mandar(int $usuarioId, string $mensaje, string $idOperacion, DateTimeImmutable $ahora): ?bool
    {
        $token = Config::get('INTERNAL_TOKEN');
        if (!is_string($token) || $token === '') {
            Logger::error('Inventario: falta INTERNAL_TOKEN para mandar avisos de la tienda');
            return null;
        }

        $nuevo = false;
        foreach (self::CANALES as $sufijo => $canal) {
            $resultado = self::llamar($token, [
                'usuario_id' => $usuarioId,
                'tipo' => 'COMPRA',
                'canal' => $canal,
                'mensaje' => $mensaje,
                'fecha_programada' => $ahora->format('Y-m-d H:i:s'),
                'id_operacion' => "$idOperacion-$sufijo",
            ]);
            if ($resultado === null) {
                return null;
            }
            $nuevo = $nuevo || $resultado;
        }

        return $nuevo;
    }

    // Una llamada a Backend. Devuelve el "aplicado" de la respuesta (true si es nueva) o null si falló.
    private static function llamar(string $token, array $cuerpo): ?bool
    {
        $base = Config::get('BACKEND_INTERNAL_URL') ?: 'http://127.0.0.1:9001';

        $contexto = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nX-Servicio-Token: $token\r\n",
            'content' => json_encode($cuerpo, JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
            'ignore_errors' => true,   // para leer el cuerpo también cuando responde un error
        ]]);

        $respuesta = @file_get_contents($base . '/internal/notificaciones', false, $contexto);

        $cabeceras = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);
        $codigo = preg_match('#^HTTP/\S+\s+(\d{3})#', $cabeceras[0] ?? '', $coincidencia) ? (int) $coincidencia[1] : 0;

        $datos = is_string($respuesta) ? json_decode($respuesta, true) : null;
        if ($codigo !== 200 || !is_array($datos) || !is_bool($datos['aplicado'] ?? null)) {
            Logger::error("Inventario: Backend no recibió el aviso {$cuerpo['id_operacion']} (HTTP $codigo)");
            return null;
        }

        return $datos['aplicado'];
    }
}
