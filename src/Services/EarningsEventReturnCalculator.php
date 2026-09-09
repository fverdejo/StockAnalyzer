<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use InvalidArgumentException;
use StockAnalyzer\DTO\EarningsEvent;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Retorno posterior a un anuncio, con una convencion deliberadamente
 * conservadora contra el look-ahead:
 *
 * - nunca compra en la fecha `reportDate`, ni siquiera si EODHD indica BMO;
 * - entra a la apertura de la primera sesion estrictamente posterior;
 * - sale al cierre N sesiones despues;
 * - compara SPY entre exactamente las mismas fechas y precios open/close.
 */
final class EarningsEventReturnCalculator
{
    private const MAX_ENTRY_DELAY_CALENDAR_DAYS = 7;

    /**
     * @param list<HistoricalQuote> $stockHistory Debe estar ordenado ascendentemente por fecha.
     * @param array<string,HistoricalQuote> $benchmarkByDate Indexado por Y-m-d.
     * @return array{
     *   entry_date:string,
     *   exit_date:string,
     *   stock_return_pct:float,
     *   benchmark_return_pct:float,
     *   market_adjusted_return_pct:float
     * }|null
     */
    public function calculate(
        EarningsEvent $event,
        array $stockHistory,
        array $benchmarkByDate,
        int $horizonSessions
    ): ?array {
        if ($horizonSessions < 1) {
            throw new InvalidArgumentException('El horizonte debe ser al menos una sesion.');
        }

        $entryIndex = $this->firstIndexAfter($stockHistory, $event->reportDate->format('Y-m-d'));

        if ($entryIndex === null) {
            return null;
        }

        $exitIndex = $entryIndex + $horizonSessions;

        if (!isset($stockHistory[$exitIndex])) {
            return null;
        }

        $entryQuote = $stockHistory[$entryIndex];
        $exitQuote = $stockHistory[$exitIndex];
        $calendarDelay = $event->reportDate->diff($entryQuote->getDate())->days;

        // Si el historico empieza mucho despues del anuncio, la busqueda
        // binaria devolveria indebidamente su primera vela como entrada.
        // Siete dias cubren fines de semana y festivos sin aceptar ese
        // emparejamiento falso ni huecos largos de cotizacion.
        if ($calendarDelay > self::MAX_ENTRY_DELAY_CALENDAR_DAYS) {
            return null;
        }

        $entryDate = $entryQuote->getDate()->format('Y-m-d');
        $exitDate = $exitQuote->getDate()->format('Y-m-d');
        $benchmarkEntry = $benchmarkByDate[$entryDate] ?? null;
        $benchmarkExit = $benchmarkByDate[$exitDate] ?? null;

        if (
            !$benchmarkEntry instanceof HistoricalQuote
            || !$benchmarkExit instanceof HistoricalQuote
            || $entryQuote->getOpen() <= 0.0
            || $benchmarkEntry->getOpen() <= 0.0
            || $exitQuote->getClose() <= 0.0
            || $benchmarkExit->getClose() <= 0.0
        ) {
            return null;
        }

        $stockReturn = (($exitQuote->getClose() / $entryQuote->getOpen()) - 1.0) * 100.0;
        $benchmarkReturn = (($benchmarkExit->getClose() / $benchmarkEntry->getOpen()) - 1.0) * 100.0;

        return [
            'entry_date' => $entryDate,
            'exit_date' => $exitDate,
            'stock_return_pct' => $stockReturn,
            'benchmark_return_pct' => $benchmarkReturn,
            'market_adjusted_return_pct' => $stockReturn - $benchmarkReturn,
        ];
    }

    /**
     * @param list<HistoricalQuote> $history
     */
    private function firstIndexAfter(array $history, string $reportDate): ?int
    {
        $low = 0;
        $high = count($history);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($history[$middle]->getDate()->format('Y-m-d') <= $reportDate) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return isset($history[$low]) ? $low : null;
    }
}
