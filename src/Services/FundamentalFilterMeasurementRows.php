<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use LogicException;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\Concerns\StopLossExitCalculator;

/**
 * Construye, POR ENTRADA, las filas del protocolo de utilidad del motor
 * predeclarado el 2026-09-22 (v1 `analista-mercado`, auditado por
 * `auditor-estadistico`, revisado por `gestor-riesgo`, cerrado en arbitraje
 * final; ver versions.md). Tres variantes con la MISMA entrada y el MISMO
 * stop fijo:
 *
 * - **A** (tecnica): el brazo fijo tal cual `PolicyReplaySimulator::replay()`
 *   ya lo calcula -- CERO logica nueva, se recibe como parametro.
 * - **B** (tecnica + filtro D/E point-in-time): identica a A si
 *   Deuda/Patrimonio en la fecha de la SEÑAL (`entry_index - 1`, un dia de
 *   mercado antes de la entrada) es `< 2,0`; si es `>= 2,0` se declina (el
 *   capital que habria ocupado esa operacion se trata como caja al 0% esa
 *   ventana, Fase 1 sin reentradas); si no es evaluable con disponibilidad
 *   historica acreditada, la entrada queda FUERA de la cohorte primaria de
 *   B (nunca aprobada por defecto).
 * - **C** (comparador simple): la MISMA entrada de A, mantenida H sesiones
 *   fijas SIN stop, valorada al cierre de `entry_index + H` con el mismo
 *   `netReturn()` (open de entrada SIN redondear), incondicional -- no
 *   depende de si A cruzo el stop.
 *
 * El umbral 2,0 no se eligio mirando el resultado de este experimento: es
 * el peor tramo YA codificado en `Analyzer\FundamentalAnalyzer::fundamentalHealth()`
 * (`< 2,0 => 3 puntos, default => 1`). El D/E se lee con
 * `Repository\PreloadedFundamentalsHistoryRepository::findAsOfWithDate()`
 * contra `fundamentals_history` (la tabla REAL, nunca `_v2110`) -- resuelto
 * FUERA de esta clase (pura, sin base de datos) y pasado ya calculado por
 * `entry_index`, igual que hace el resto de este archivo con los datos que
 * consume.
 *
 * Cohorte primaria (H comun, sin censura): solo entradas con
 * `entry_index + $horizon <= ultimo indice`, igual criterio que la
 * medicion de trailing.
 */
final class FundamentalFilterMeasurementRows
{
    use StopLossExitCalculator;

    public const DEBT_TO_EQUITY_THRESHOLD = 2.0;

    public function __construct(
        private readonly int $horizon = 45,
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig()
    ) {
    }

    protected function getCostRate(): float
    {
        return $this->backtestingConfig->getCostRate();
    }

    /**
     * @param list<array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, managed_return: float}> $fixedTrades trades del brazo A (`PolicyReplaySimulator::replay()['trades']`)
     * @param list<HistoricalQuote> $history MISMO historial que genero `$fixedTrades`
     * @param array<int, array{value: ?float, evaluable: bool, snapshot_date: ?string, signal_date: string, not_evaluable_reason: ?string}> $debtToEquityByEntryIndex ya resuelto (sin BD aqui), una entrada por `entry_index` de `$fixedTrades`
     * @return list<array<string, mixed>>
     */
    public function rows(string $ticker, array $fixedTrades, array $history, array $debtToEquityByEntryIndex, string $sector): array
    {
        $lastIndex = count($history) - 1;
        $rows = [];

        foreach ($fixedTrades as $trade) {
            $entryIndex = (int) $trade['entry_index'];
            $inCohort = $entryIndex + $this->horizon <= $lastIndex;

            $de = $debtToEquityByEntryIndex[$entryIndex] ?? null;

            if ($de === null) {
                throw new LogicException(sprintf('%s: falta la resolucion de D/E para la entrada de indice %d.', $ticker, $entryIndex));
            }

            $row = [
                'ticker' => strtoupper($ticker),
                'entry_date' => $trade['entry_date'],
                'entry_index' => $entryIndex,
                'sector' => $sector,
                'in_cohort' => $inCohort,
                'signal_date' => $de['signal_date'],
                'de_evaluable' => $de['evaluable'],
                'de_value' => $de['value'],
                'de_snapshot_date' => $de['snapshot_date'],
                'de_not_evaluable_reason' => $de['not_evaluable_reason'] ?? null,
                'de_rejected' => $de['evaluable'] && $de['value'] >= self::DEBT_TO_EQUITY_THRESHOLD,
                'v_a' => null,
                'v_c' => null,
                'v_b' => null,
                'd' => null,
                'd_prime' => null,
                'mfe_a_close' => null,
            ];

            if (!$inCohort) {
                $rows[] = $row;

                continue;
            }

            $horizonIndex = $entryIndex + $this->horizon;
            $open = $history[$entryIndex]->getOpen();
            $exitIndex = (int) $trade['exit_index'];

            // Assert 6 (predeclaracion): V_C coincide exactamente con V_A
            // cuando el brazo fijo NO cruza el stop antes de horizonte --
            // ambos leen el MISMO cierre con el MISMO netReturn().
            $vA = $exitIndex <= $horizonIndex ? (float) $trade['managed_return'] : $this->netReturn($open, $history[$horizonIndex]->getClose());
            $vC = $this->netReturn($open, $history[$horizonIndex]->getClose());

            if ($exitIndex > $horizonIndex && abs($vA - $vC) > 1e-9) {
                throw new LogicException(sprintf('%s: V_A != V_C sin salida del brazo fijo antes de horizonte (entrada %d).', $ticker, $entryIndex));
            }

            $row['v_a'] = $vA;
            $row['v_c'] = $vC;
            $row['d_prime'] = $vA - $vC;

            // MFE (maximo cierre) hasta horizonte, sobre el open de entrada:
            // solo descriptivo (percentiles P5/P1 pedidos por gestor-riesgo).
            $maxClose = $history[$entryIndex]->getClose();

            for ($i = $entryIndex + 1; $i <= $horizonIndex; $i++) {
                $maxClose = max($maxClose, $history[$i]->getClose());
            }

            $row['mfe_a_close'] = ($maxClose / $open - 1) * 100;

            if ($de['evaluable']) {
                $vB = $row['de_rejected'] ? 0.0 : $vA;

                // Assert 2/3 de la predeclaracion.
                if (!$row['de_rejected'] && abs($vB - $vA) > 1e-9) {
                    throw new LogicException(sprintf('%s: V_B != V_A con D/E < 2,0 (entrada %d).', $ticker, $entryIndex));
                }

                if ($row['de_rejected'] && $vB !== 0.0) {
                    throw new LogicException(sprintf('%s: V_B != 0 exacto con D/E >= 2,0 (entrada %d).', $ticker, $entryIndex));
                }

                $row['v_b'] = $vB;
                $row['d'] = $vB - $vA;
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
