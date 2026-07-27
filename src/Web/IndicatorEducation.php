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

        foreach (array_slice($signals, 0, 4) as $signal) {
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
            'RSI' => 'El RSI ayuda a detectar si el movimiento reciente esta demasiado estirado. No decide solo, pero evita comprar impulsos agotados o vender caidas ya extremas.',
            'MACD' => 'MACD compara medias exponenciales rapidas y lentas. Cuando mejora, el impulso reciente gana fuerza; cuando se deteriora, la tendencia pierde apoyo.',
            'Bandas de Bollinger' => 'Las bandas muestran si el precio esta cerca de extremos estadisticos recientes. Eso puede indicar presion excesiva o una ruptura fuerte.',
            'Momentum 30 dias' => 'El momentum mide si el precio ha avanzado o retrocedido en el ultimo mes de mercado. Es util para separar fuerza real de rebotes aislados.',
            'Rango medio diario (ATR)' => 'ATR aproxima cuanto se mueve la accion cada dia. Un ATR alto no es malo por si mismo, pero aumenta el riesgo de entrada.',
            'PER', 'PEG', 'EV/EBITDA', 'Precio/Valor contable' => 'Los ratios de valoracion comparan precio con beneficios, crecimiento, balance o capacidad operativa. Ayudan a no pagar demasiado por una buena empresa.',
            'ROE', 'Margenes', 'Flujo de caja libre' => 'Las metricas de calidad comprueban si el negocio convierte ventas y capital en beneficios o caja de forma consistente.',
            'Deuda/Patrimonio', 'Ratio de liquidez' => 'Estas metricas resumen fortaleza financiera. Una deuda contenida y liquidez suficiente reducen fragilidad en fases complicadas.',
            'Dividendos' => 'El dividendo solo suma cuando parece sostenible. Una rentabilidad alta con payout excesivo puede ser una senal de riesgo, no de calidad.',
            default => 'Esta senal procede del mismo calculo que aporta puntos al score, por eso la explicacion y la cifra salen de una unica fuente.',
        };
    }
}
