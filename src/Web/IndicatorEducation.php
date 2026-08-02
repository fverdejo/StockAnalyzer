<?php

declare(strict_types=1);

namespace StockAnalyzer\Web;

use StockAnalyzer\DTO\Signal;
use StockAnalyzer\Enums\SignalVerdict;

class IndicatorEducation
{
    /**
     * @param list<Signal> $positive
     * @param list<Signal> $negative
     * @param list<Signal> $neutral
     */
    public static function render(array $positive, array $negative, array $neutral): string
    {
        $signals = array_merge($positive, $negative, $neutral);

        if ($signals === []) {
            return '';
        }

        usort($signals, static function (Signal $left, Signal $right): int {
            return self::priority($right) <=> self::priority($left);
        });

        $items = [];

        foreach (self::pickBalanced($signals, 4) as $signal) {
            $items[] = sprintf(
                '<div class="education-item %s"><strong>%s</strong><p>%s</p><p class="muted">%s</p></div>',
                $signal->getVerdict()->cssClass(),
                Layout::escape($signal->getLabel()),
                Layout::escape(self::expand($signal)),
                Layout::escape($signal->getMessage())
            );
        }

        return '<section class="panel"><h2>Indicadores determinantes</h2><div class="education-grid">' . implode('', $items) . '</div></section>';
    }

    /**
     * Reparte los indicadores mostrados entre tecnicos y fundamentales
     * (ver versions.md v2.17), alternando entre ambos grupos (ya
     * ordenados por prioridad) en vez de tomar sin mas los `$limit`
     * primeros del array combinado: el analisis tecnico genera mas
     * Signal por accion que el fundamental, asi que sin este reparto los
     * fundamentales casi nunca aparecian aqui, aunque tambien
     * determinan la puntuacion.
     *
     * @param list<Signal> $signals ya ordenados por prioridad
     * @return list<Signal>
     */
    private static function pickBalanced(array $signals, int $limit): array
    {
        $technical = array_values(array_filter($signals, static fn (Signal $signal): bool => $signal->isTechnical()));
        $fundamental = array_values(array_filter($signals, static fn (Signal $signal): bool => !$signal->isTechnical()));

        $picked = [];
        $t = 0;
        $f = 0;

        while (count($picked) < $limit && ($t < count($technical) || $f < count($fundamental))) {
            if ($f < count($fundamental)) {
                $picked[] = $fundamental[$f++];

                if (count($picked) >= $limit) {
                    break;
                }
            }

            if ($t < count($technical)) {
                $picked[] = $technical[$t++];
            }
        }

        return array_slice($picked, 0, $limit);
    }

    private static function priority(Signal $signal): int
    {
        return match ($signal->getVerdict()) {
            SignalVerdict::POSITIVE, SignalVerdict::NEGATIVE => 2,
            SignalVerdict::NEUTRAL => 1,
        };
    }

    private static function expand(Signal $signal): string
    {
        return match ($signal->getLabel()) {
            'Precio vs SMA20', 'Precio vs SMA50' => 'La comparacion entre precio y medias moviles resume si la accion cotiza por encima o por debajo de su tendencia reciente.',
            'Cruce de medias' => 'Cuando la media corta cruza por encima de la larga (cruce alcista) suele leerse como cambio de tendencia a favor; el cruce contrario, en contra.',
            'RSI', 'RSI (14)' => 'El RSI ayuda a detectar si el movimiento reciente esta demasiado estirado. No decide solo, pero evita comprar impulsos agotados o vender caidas ya extremas.',
            'MACD' => 'MACD compara medias exponenciales rapidas y lentas. Cuando mejora, el impulso reciente gana fuerza; cuando se deteriora, la tendencia pierde apoyo.',
            'Bandas de Bollinger' => 'Las bandas muestran si el precio esta cerca de extremos estadisticos recientes. Eso puede indicar presion excesiva o una ruptura fuerte.',
            'Momentum 30 dias', 'Momentum 30d' => 'El momentum mide si el precio ha avanzado o retrocedido en el ultimo mes de mercado. Es util para separar fuerza real de rebotes aislados.',
            'Volumen' => 'Un movimiento de precio con volumen alto es mas fiable que el mismo movimiento con volumen escaso: mas participantes respaldan la subida o bajada.',
            'Rango medio diario (ATR)', 'ATR (14)' => 'ATR aproxima cuanto se mueve la accion cada dia. Un ATR alto no es malo por si mismo, pero aumenta el riesgo de entrada.',
            'Volatilidad 20 dias', 'Volatilidad 20d' => 'La volatilidad mide cuanto varia el precio dia a dia. Cuanto mayor, mas riesgo (y mas rango de movimiento posible) en poco tiempo.',
            'PER', 'PEG', 'EV/EBITDA', 'Precio/Valor contable' => 'Los ratios de valoracion comparan precio con beneficios, crecimiento, balance o capacidad operativa fundamental de la empresa. Ayudan a no pagar demasiado por un buen negocio.',
            'ROE', 'Flujo de caja libre' => 'Las metricas de calidad fundamental comprueban si el negocio convierte ventas y capital en beneficios o caja de forma consistente, no solo si el grafico se ve bien.',
            'Margen neto', 'Margen operativo' => 'Los margenes fundamentales indican que porcentaje de cada venta se queda la empresa como beneficio. Margenes altos y estables suelen reflejar ventaja competitiva.',
            'Deuda/Patrimonio', 'Ratio de liquidez' => 'Estas metricas fundamentales resumen fortaleza financiera. Una deuda contenida y liquidez suficiente reducen fragilidad en fases complicadas.',
            'Dividendos', 'Rentabilidad por dividendo', 'Payout ratio' => 'El dividendo fundamental solo suma cuando parece sostenible. Una rentabilidad alta con payout excesivo puede ser una senal de riesgo, no de calidad.',
            default => 'Esta senal procede del mismo calculo que aporta puntos al score, por eso la explicacion y la cifra salen de una unica fuente.',
        };
    }
}
