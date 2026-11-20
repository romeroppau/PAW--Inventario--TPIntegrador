<?php

// Las reglas de una compra de la tienda: los plazos, los recordatorios y el texto de los avisos.
// No tiene SQL: la usan CompraRepositorio y CompraControlador. Contrato: docs/api/contrato-tienda.md.
//
// Todas las funciones reciben la fecha de la compra y "ahora" como datos, en lugar de mirar el reloj:
// así se pueden probar con cualquier día (tests/CompraTest.php). Las fechas son de Argentina.
class Compra
{
    // Plazos (contrato, "Plazos").
    public const MINUTOS_PARA_PAGAR = 15;
    public const DIAS_HABILES_PARA_RETIRAR = 5;

    // Los recordatorios y las cancelaciones por vencimiento salen desde esta hora (no de madrugada).
    // Las cancelaciones por falta de pago (15 minutos) no esperan: salen a cualquier hora.
    public const HORA_DE_AVISOS = 9;

    // Días hábiles en que se manda cada recordatorio.
    public const DIA_PRIMER_RECORDATORIO = 2;
    public const DIA_ULTIMO_RECORDATORIO = 4;

    private const DIAS_DE_LA_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    // Número de factura: V- y el id con 6 dígitos (las de consulta empiezan con C-).
    public static function numero(int $id): string
    {
        return 'V-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    // ---------- Plazos ----------

    // Hasta cuándo se puede pagar: 15 minutos desde la confirmación.
    public static function pagarAntesDe(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return $fecha->modify('+' . self::MINUTOS_PARA_PAGAR . ' minutes');
    }

    // El día hábil número $n después de la fecha (lunes a viernes; los feriados no se cuentan).
    // Ejemplo: confirmada el miércoles 18, el día hábil 1 es el jueves 19 y el 2 el viernes 20.
    public static function diaHabil(DateTimeImmutable $fecha, int $n): DateTimeImmutable
    {
        $dia = $fecha->setTime(0, 0);
        $contados = 0;

        while ($contados < $n) {
            $dia = $dia->modify('+1 day');
            if ((int) $dia->format('N') <= 5) {   // N: 1 = lunes ... 7 = domingo
                $contados++;
            }
        }

        return $dia;
    }

    // El último día para retirar (inclusive): el 5.º día hábil.
    public static function retirarHasta(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return self::diaHabil($fecha, self::DIAS_HABILES_PARA_RETIRAR);
    }

    // ¿Una compra sin pagar ya pasó los 15 minutos?
    public static function vencioSinPagar(DateTimeImmutable $fecha, DateTimeImmutable $ahora): bool
    {
        return $ahora >= self::pagarAntesDe($fecha);
    }

    // ¿Una compra pagada ya se puede cancelar por no retirarla? Desde las 9:00 del día siguiente al último.
    public static function vencioSinRetirar(DateTimeImmutable $fecha, DateTimeImmutable $ahora): bool
    {
        return $ahora >= self::cancelacionPorVencimiento($fecha);
    }

    // ¿Hoy toca el aviso de cancelación? El mismo día de la cancelación, desde las 9:00 (como los
    // recordatorios: cada aviso sale solo en su día).
    public static function avisoDeCancelacionHoy(DateTimeImmutable $fecha, DateTimeImmutable $ahora): bool
    {
        $cancelacion = self::cancelacionPorVencimiento($fecha);

        return $ahora >= $cancelacion && $ahora->setTime(0, 0) == $cancelacion->setTime(0, 0);
    }

    // Cuándo se cancela una compra pagada que no se retiró: a las 9:00 del día siguiente al último.
    private static function cancelacionPorVencimiento(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return self::retirarHasta($fecha)->modify('+1 day')->setTime(self::HORA_DE_AVISOS, 0);
    }

    // ---------- Recordatorios ----------

    // Qué recordatorio le toca hoy a una compra pagada: 'dia2', 'dia4' o null.
    // Cada uno sale solo en su día, desde las 9:00. Si un intento falla, la tarea lo reintenta en su
    // próxima corrida (cada 15 minutos) del mismo día; el id_operacion evita que salga dos veces.
    public static function recordatorioDeHoy(DateTimeImmutable $fecha, DateTimeImmutable $ahora): ?string
    {
        if ((int) $ahora->format('G') < self::HORA_DE_AVISOS) {
            return null;
        }

        $hoy = $ahora->setTime(0, 0);

        if ($hoy == self::diaHabil($fecha, self::DIA_PRIMER_RECORDATORIO)) {
            return 'dia2';
        }
        if ($hoy == self::diaHabil($fecha, self::DIA_ULTIMO_RECORDATORIO)) {
            return 'dia4';
        }

        return null;
    }

    // ---------- Textos de los avisos ----------
    // Cada mensaje va en un solo renglón: WhatsApp no acepta saltos de línea en la plantilla.

    // El primer nombre de la cuenta, para el saludo ("Ana Pérez" -> "Ana").
    public static function primerNombre(string $nombre): string
    {
        $partes = preg_split('/\s+/', trim($nombre));

        return $partes[0] !== '' ? $partes[0] : $nombre;
    }

    public static function mensajeDia2(string $nombre, string $numero, DateTimeImmutable $fecha): string
    {
        return sprintf(
            '🐾 ¡Hola, %s! Tu pedido %s ya te está esperando en HuellaVet. Podés pasar a buscarlo hasta el %s inclusive. '
            . '¡Te esperamos! 🛍️ Política de cancelación: si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra.',
            self::primerNombre($nombre),
            $numero,
            self::diaConNombre(self::retirarHasta($fecha))
        );
    }

    public static function mensajeDia4(string $nombre, string $numero, DateTimeImmutable $fecha): string
    {
        return sprintf(
            '⏰ ¡Hola, %s! Te recordamos que mañana (%s) es el último día para retirar tu pedido %s. '
            . 'Pasado ese día, el pedido se cancela y el importe no se reintegra.',
            self::primerNombre($nombre),
            self::retirarHasta($fecha)->format('d/m'),
            $numero
        );
    }

    public static function mensajeCancelada(string $nombre, string $numero, DateTimeImmutable $fecha): string
    {
        return sprintf(
            'Hola, %s. Tu pedido %s se canceló porque venció el plazo de retiro (%s). '
            . 'Como te avisamos, el importe no se reintegra. Si tenés alguna duda, escribinos. 🐾',
            self::primerNombre($nombre),
            $numero,
            self::retirarHasta($fecha)->format('d/m')
        );
    }

    // Cuando el comprador cancela un pedido que ya pagó.
    public static function mensajeCanceladaPorComprador(string $nombre, string $numero): string
    {
        return sprintf(
            'Hola, %s. Cancelaste tu pedido %s y los productos volvieron a la tienda. '
            . 'Como indica la política de cancelación, el importe no se reintegra. '
            . 'Si tenés alguna duda, escribinos. ¡Te esperamos pronto en HuellaVet! 🐾',
            self::primerNombre($nombre),
            $numero
        );
    }

    // Un pago que no se pudo usar y se devolvió. El texto depende de cómo estaba la compra:
    // - CANCELADA: el pago llegó cuando ya había terminado el tiempo para pagar (y no quedaba stock);
    // - PAGADA o ENTREGADA: el pedido ya estaba pagado (pagó dos veces, por ejemplo desde otra pestaña);
    // - otro caso (no debería pasar): un texto general.
    // Si el pago tardío sí se pudo usar, no hay un aviso especial: le llega el de "Recibimos el pago".
    public static function mensajePagoDevuelto(string $nombre, string $numero, string $estadoCompra): string
    {
        $motivo = match ($estadoCompra) {
            'CANCELADA' => "Tu pago del pedido $numero llegó cuando ya había terminado el tiempo para pagar, así que te devolvimos el dinero.",
            'PAGADA', 'ENTREGADA' => "Tu pedido $numero ya estaba pagado, así que te devolvimos el pago que hiciste de más.",
            default => "No pudimos usar tu pago del pedido $numero, así que te devolvimos el dinero.",
        };

        return sprintf('Hola, %s. %s Puede tardar unos días en verse en tu medio de pago. 🐾', self::primerNombre($nombre), $motivo);
    }

    // Al pagar: la compra ya está paga y lista para retirar (queda como constancia).
    public static function mensajePagada(string $nombre, string $numero, DateTimeImmutable $fecha): string
    {
        return sprintf(
            '✅ ¡Hola, %s! Recibimos el pago de tu pedido %s. Ya podés retirarlo en HuellaVet hasta el %s inclusive. ¡Te esperamos!',
            self::primerNombre($nombre),
            $numero,
            self::diaConNombre(self::retirarHasta($fecha))
        );
    }

    // A la veterinaria, cuando una compra queda paga: hay un pedido nuevo para retirar.
    public static function mensajeNuevoPedido(string $comprador, string $numero, float $total, DateTimeImmutable $fecha): string
    {
        return sprintf(
            '🛍️ Nuevo pedido para retirar: %s de %s ($ %s). Fecha límite de retiro: %s.',
            $numero,
            trim($comprador),
            number_format($total, 2, ',', '.'),
            self::diaConNombre(self::retirarHasta($fecha))
        );
    }

    // "martes 25/11"
    private static function diaConNombre(DateTimeImmutable $dia): string
    {
        return self::DIAS_DE_LA_SEMANA[(int) $dia->format('w')] . ' ' . $dia->format('d/m');
    }
}
