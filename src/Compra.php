<?php

// Las reglas de una compra de la tienda: los plazos, los recordatorios y el texto de los avisos.
// No tiene SQL: la usan CompraRepositorio y CompraControlador. Contrato: docs/api/contrato-tienda.md.
//
// Los plazos los configura la veterinaria desde su panel (tabla configuracion, catálogo de Backend:
// grupo "Tienda"). Cada compra guarda los suyos al confirmarse (pagar_antes_de y retirar_hasta,
// migración 015): un cambio en la configuración vale solo para las compras nuevas.
//
// Las funciones reciben fechas y "ahora" como datos, en lugar de mirar el reloj: así se pueden probar
// con cualquier día (tests/CompraTest.php). Las fechas son de Argentina.
class Compra
{
    // Valores por defecto de la configuración (los mismos que en CatalogoConfiguracion de Backend:
    // si se cambia uno, hay que cambiarlo en los dos lados), con su rango.
    public const MINUTOS_PARA_PAGAR = 15;             // configuracion: minutos_para_pagar (5 a 60)
    public const DIAS_HABILES_PARA_RETIRAR = 5;       // configuracion: dias_habiles_para_retirar (2 a 15)
    public const HORA_DE_AVISOS = '09:00';            // configuracion: hora_avisos_tienda (07:00 a 20:00)
    public const DIA_PRIMER_RECORDATORIO = 2;         // configuracion: dia_primer_recordatorio (1 a 13)

    // El link de Mercado Pago vence este margen ANTES que la compra: así un pago que empezó a tiempo
    // tiene un minuto para terminar antes de que la compra se cancele (casi no hay pagos tardíos).
    public const MINUTOS_DE_MARGEN_DEL_LINK = 1;

    private const DIAS_DE_LA_SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

    // Número de factura: V- y el id con 6 dígitos (las de consulta empiezan con C-).
    public static function numero(int $id): string
    {
        return 'V-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    // ---------- Configuración ----------
    // Se leen con comun/configuracion.php. Sin la configuración (las pruebas unitarias, que no cargan
    // comun/) o si algo falla, se usan los valores por defecto: la tienda anda igual que antes.

    public static function minutosParaPagar(): int
    {
        return self::entero('minutos_para_pagar', self::MINUTOS_PARA_PAGAR, 5, 60);
    }

    public static function diasHabilesParaRetirar(): int
    {
        return self::entero('dias_habiles_para_retirar', self::DIAS_HABILES_PARA_RETIRAR, 2, 15);
    }

    // "HH:MM": desde esta hora salen los recordatorios y se cancelan los pedidos no retirados.
    // Las cancelaciones por falta de pago no esperan: salen a cualquier hora.
    public static function horaDeAvisos(): string
    {
        return class_exists('Configuracion') ? Configuracion::hora('hora_avisos_tienda', self::HORA_DE_AVISOS) : self::HORA_DE_AVISOS;
    }

    // En qué día hábil sale el primer recordatorio. El último sale siempre el día hábil anterior al
    // último día para retirar (ultimoRecordatorio).
    public static function diaPrimerRecordatorio(): int
    {
        return self::entero('dia_primer_recordatorio', self::DIA_PRIMER_RECORDATORIO, 1, 13);
    }

    private static function entero(string $clave, int $defecto, int $minimo, int $maximo): int
    {
        return class_exists('Configuracion') ? Configuracion::entero($clave, $defecto, $minimo, $maximo) : $defecto;
    }

    // ---------- Plazos de una compra nueva (se guardan al confirmar) ----------

    // Hasta cuándo se puede pagar: los minutos configurados desde la confirmación.
    public static function pagarAntesDe(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return $fecha->modify('+' . self::minutosParaPagar() . ' minutes');
    }

    // El último día para retirar (inclusive): el día hábil número "plazo para retirar".
    public static function retirarHasta(DateTimeImmutable $fecha): DateTimeImmutable
    {
        return self::diaHabil($fecha, self::diasHabilesParaRetirar());
    }

    // ---------- Días hábiles ----------

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

    // El día hábil anterior a un día (el de un lunes es el viernes).
    public static function diaHabilAnterior(DateTimeImmutable $dia): DateTimeImmutable
    {
        $anterior = $dia->setTime(0, 0)->modify('-1 day');
        while ((int) $anterior->format('N') > 5) {
            $anterior = $anterior->modify('-1 day');
        }

        return $anterior;
    }

    // Cuántos días hábiles hay desde la compra hasta el último día para retirar (para la política).
    public static function diasHabilesEntre(DateTimeImmutable $fecha, DateTimeImmutable $retirarHasta): int
    {
        $dias = 0;
        while (self::diaHabil($fecha, $dias + 1) <= $retirarHasta->setTime(0, 0) && $dias < 60) {
            $dias++;
        }

        return $dias;
    }

    // ---------- Vencimientos (con los plazos guardados de cada compra) ----------

    // Hasta cuándo se puede EMPEZAR a pagar en Mercado Pago: 1 minuto antes de que venza la compra.
    public static function linkDePagoVence(DateTimeImmutable $pagarAntesDe): DateTimeImmutable
    {
        return $pagarAntesDe->modify('-' . self::MINUTOS_DE_MARGEN_DEL_LINK . ' minutes');
    }

    // ¿Una compra sin pagar ya pasó su tiempo para pagar?
    public static function vencioSinPagar(DateTimeImmutable $pagarAntesDe, DateTimeImmutable $ahora): bool
    {
        return $ahora >= $pagarAntesDe;
    }

    // ¿Una compra pagada ya se puede cancelar por no retirarla? Desde la hora de los avisos del día
    // siguiente al último día para retirar.
    public static function vencioSinRetirar(DateTimeImmutable $retirarHasta, DateTimeImmutable $ahora): bool
    {
        return $ahora >= self::cancelacionPorVencimiento($retirarHasta);
    }

    // ¿Hoy toca el aviso de cancelación? El mismo día de la cancelación, desde la hora de los avisos
    // (como los recordatorios: cada aviso sale solo en su día).
    public static function avisoDeCancelacionHoy(DateTimeImmutable $retirarHasta, DateTimeImmutable $ahora): bool
    {
        $cancelacion = self::cancelacionPorVencimiento($retirarHasta);

        return $ahora >= $cancelacion && $ahora->setTime(0, 0) == $cancelacion->setTime(0, 0);
    }

    private static function cancelacionPorVencimiento(DateTimeImmutable $retirarHasta): DateTimeImmutable
    {
        [$hora, $minuto] = array_map('intval', explode(':', self::horaDeAvisos()));

        return $retirarHasta->setTime(0, 0)->modify('+1 day')->setTime($hora, $minuto);
    }

    // ---------- Recordatorios ----------

    // Qué recordatorio le toca hoy a una compra pagada: 'dia2' (el primero), 'dia4' (el último) o null.
    // (Los códigos quedaron de cuando eran fijos los días 2 y 4: son parte del id_operacion de cada
    // aviso y no se cambian, así un aviso ya mandado no se repite.)
    // - El primero: el día hábil configurado después de la compra.
    // - El último: el día hábil anterior al último día para retirar.
    // Cada uno sale solo en su día, desde la hora de los avisos. Si un intento falla, la tarea lo
    // reintenta en su próxima corrida (cada 15 minutos) del mismo día; el id_operacion evita que salga dos veces.
    public static function recordatorioDeHoy(DateTimeImmutable $fecha, DateTimeImmutable $retirarHasta, DateTimeImmutable $ahora): ?string
    {
        if ($ahora->format('H:i') < self::horaDeAvisos()) {
            return null;
        }

        $hoy = $ahora->setTime(0, 0);
        $ultimo = self::diaHabilAnterior($retirarHasta);

        if ($hoy == $ultimo) {
            return 'dia4';
        }
        if ($hoy == self::diaHabil($fecha, self::diaPrimerRecordatorio()) && $hoy < $ultimo) {
            return 'dia2';
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

    public static function mensajeDia2(string $nombre, string $numero, DateTimeImmutable $retirarHasta): string
    {
        return sprintf(
            '🐾 ¡Hola, %s! Tu pedido %s ya te está esperando en HuellaVet. Podés pasar a buscarlo hasta el %s inclusive. '
            . '¡Te esperamos! 🛍️ Política de cancelación: si no lo retirás en ese plazo, el pedido se cancela y el importe no se reintegra.',
            self::primerNombre($nombre),
            $numero,
            self::diaConNombre($retirarHasta)
        );
    }

    // El último recordatorio sale el día hábil anterior al último día: no siempre es "mañana" (si el
    // último día es un lunes, sale el viernes). Por eso dice el día con su nombre.
    public static function mensajeDia4(string $nombre, string $numero, DateTimeImmutable $retirarHasta): string
    {
        return sprintf(
            '⏰ ¡Hola, %s! Te recordamos que el %s es el último día para retirar tu pedido %s. '
            . 'Pasado ese día, el pedido se cancela y el importe no se reintegra.',
            self::primerNombre($nombre),
            self::diaConNombre($retirarHasta),
            $numero
        );
    }

    public static function mensajeCancelada(string $nombre, string $numero, DateTimeImmutable $retirarHasta): string
    {
        return sprintf(
            'Hola, %s. Tu pedido %s se canceló porque venció el plazo de retiro (%s). '
            . 'Como te avisamos, el importe no se reintegra. Si tenés alguna duda, escribinos. 🐾',
            self::primerNombre($nombre),
            $numero,
            $retirarHasta->format('d/m')
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
    // - CANCELADA: el pago llegó cuando ya había terminado el tiempo para pagar y otro se llevó el stock;
    // - PAGADA o ENTREGADA: el pedido ya estaba pagado (pagó dos veces, por ejemplo desde otra pestaña);
    // - otro caso (no debería pasar): un texto general.
    // Si el pago tardío sí se pudo usar, no hay un aviso especial: le llega el de "Recibimos el pago".
    public static function mensajePagoDevuelto(string $nombre, string $numero, string $estadoCompra): string
    {
        $motivo = match ($estadoCompra) {
            'CANCELADA' => "Tu pago del pedido $numero llegó cuando ya había terminado el tiempo para pagar y, mientras tanto, "
                . 'otra persona compró los productos que habías elegido y no quedó stock, así que te devolvimos el dinero.',
            'PAGADA', 'ENTREGADA' => "Tu pedido $numero ya estaba pagado, así que te devolvimos el pago que hiciste de más.",
            default => "No pudimos usar tu pago del pedido $numero, así que te devolvimos el dinero.",
        };

        return sprintf('Hola, %s. %s Puede tardar unos días en verse en tu medio de pago. 🐾', self::primerNombre($nombre), $motivo);
    }

    // Al pagar: la compra ya está paga y lista para retirar (queda como constancia).
    public static function mensajePagada(string $nombre, string $numero, DateTimeImmutable $retirarHasta): string
    {
        return sprintf(
            '✅ ¡Hola, %s! Recibimos el pago de tu pedido %s. Ya podés retirarlo en HuellaVet hasta el %s inclusive. ¡Te esperamos!',
            self::primerNombre($nombre),
            $numero,
            self::diaConNombre($retirarHasta)
        );
    }

    // A la veterinaria, cuando una compra queda paga: hay un pedido nuevo para retirar.
    public static function mensajeNuevoPedido(string $comprador, string $numero, float $total, DateTimeImmutable $retirarHasta): string
    {
        return sprintf(
            '🛍️ Nuevo pedido para retirar: %s de %s ($ %s). Fecha límite de retiro: %s.',
            $numero,
            trim($comprador),
            number_format($total, 2, ',', '.'),
            self::diaConNombre($retirarHasta)
        );
    }

    // "martes 25/11"
    public static function diaConNombre(DateTimeImmutable $dia): string
    {
        return self::DIAS_DE_LA_SEMANA[(int) $dia->format('w')] . ' ' . $dia->format('d/m');
    }
}
