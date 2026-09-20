<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use LogicException;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\Concerns\StopLossExitCalculator;

/**
 * Construye, POR ENTRADA, las filas de la medicion completa de trailing a
 * horizonte comun (fase 1, predeclaracion final del `2026-09-20`, tercera
 * entrada de `versions.md`). Una fila reune lo que hace falta para
 * analizarla SIN volver a la base de datos (assert 11: se persiste tal cual):
 * el open de entrada SIN redondear, el cierre y la fecha a cada horizonte
 * `H` en `HORIZONS`, y, para cada brazo (fijo, trailing cadencia 5,
 * trailing cadencia 10), la salida SIN redondear, el valor `V` a cada `H`,
 * el `MFE` y `x = min(exit - entry, H)`.
 *
 * `V` (misma funcion en todos los brazos): si el brazo sale en
 * `entry_index + H` o antes, `V = managed_return` (neto, calculado con el
 * open sin redondear); si no, `V = netReturn(open, cierre en entry_index +
 * H)`. Nunca `exit_price` (esta redondeado a 4 decimales en los trades del
 * brazo fijo). `V` solo existe para entradas con `entry_index + H <= ultimo
 * indice` (cohorte comun: sin pendientes ni censura en ningun brazo).
 *
 * Los asserts de contabilidad que se pueden comprobar por entrada se
 * comprueban AQUI y lanzan `LogicException` (una medicion con la
 * contabilidad rota no debe seguir): mismas entradas en los tres brazos
 * (1), salida trailing <= salida fija y stop final >= inicial (2), trailing
 * desactivado reproduce el fijo canonico (3), `D_i = 0` exacto cuando
 * NINGUN brazo sale en `entry_index + H` o antes (5), `Δx >= 0` (12).
 */
final class PolicyReplayHorizonRows
{
    use StopLossExitCalculator;

    public const PRIMARY_HORIZON = 45;

    /** @var list<int> */
    public const HORIZONS = [21, 45, 90, 250];

    public function __construct(
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig()
    ) {
    }

    protected function getCostRate(): float
    {
        return $this->backtestingConfig->getCostRate();
    }

    /**
     * @param array{ticker: string, trades: list<array<string, mixed>>} $fixedCanonical replay de `PolicyReplaySimulator` (la referencia)
     * @param array{ticker: string, trades: list<array<string, mixed>>} $fixedRaw `PolicyReplayTrailingSimulator` con `trailingEnabled = false` (precios de salida sin redondear)
     * @param array{ticker: string, trades: list<array<string, mixed>>} $trailing cadencia 5
     * @param array{ticker: string, trades: list<array<string, mixed>>} $trailing10 cadencia 10
     * @param list<HistoricalQuote> $history
     * @param ?callable(string): ?bool $bearRegime `true` si el S&P 500 estaba por debajo de su SMA200 el ultimo dia ESTRICTAMENTE anterior a la fecha dada, `null` si no hay dato
     * @return list<array<string, mixed>>
     */
    public function rows(string $ticker, array $fixedCanonical, array $fixedRaw, array $trailing, array $trailing10, array $history, ?callable $bearRegime = null): array
    {
        $count = count($fixedCanonical['trades']);

        // Assert 1: mismas entradas en todos los brazos.
        foreach ([$fixedRaw, $trailing, $trailing10] as $other) {
            if (count($other['trades']) !== $count) {
                throw new LogicException("{$ticker}: distinto numero de entradas entre brazos.");
            }
        }

        $lastIndex = count($history) - 1;
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $canonical = $fixedCanonical['trades'][$i];
            $raw = $fixedRaw['trades'][$i];
            $trail = $trailing['trades'][$i];
            $trail10 = $trailing10['trades'][$i];
            $entryIndex = (int) $canonical['entry_index'];

            foreach ([$raw, $trail, $trail10] as $other) {
                if ((int) $other['entry_index'] !== $entryIndex) {
                    throw new LogicException("{$ticker}: la entrada {$i} no coincide entre brazos.");
                }
            }

            // Assert 3: el re-recorrido sin trailing reproduce el fijo canonico.
            if ($raw['exit_index'] !== $canonical['exit_index']
                || $raw['managed_return'] !== $canonical['managed_return']
                || $raw['pending'] !== $canonical['pending']
                || round((float) $raw['exit_price_raw'], 4) !== $canonical['exit_price']
            ) {
                throw new LogicException("{$ticker}: el trailing desactivado NO reproduce el brazo fijo canonico (entrada {$i}).");
            }

            // Assert 2: la salida trailing nunca es posterior a la fija y el stop nunca baja.
            foreach ([$trail, $trail10] as $arm) {
                if ($arm['exit_index'] > $canonical['exit_index'] || $arm['final_stop'] < $arm['initial_stop']) {
                    throw new LogicException("{$ticker}: violacion de contabilidad (salida posterior o stop a la baja) en la entrada {$i}.");
                }
            }

            $open = $history[$entryIndex]->getOpen();
            $closes = [];

            foreach (self::HORIZONS as $horizon) {
                $closes[$horizon] = $entryIndex + $horizon <= $lastIndex
                    ? ['date' => $history[$entryIndex + $horizon]->getDate()->format('Y-m-d'), 'close' => $history[$entryIndex + $horizon]->getClose()]
                    : null;
            }

            $arms = [
                'fixed' => $this->arm($raw, $entryIndex, $open, $closes, $history),
                'trail' => $this->arm($trail, $entryIndex, $open, $closes, $history),
                'trail10' => $this->arm($trail10, $entryIndex, $open, $closes, $history),
            ];

            $primary = self::PRIMARY_HORIZON;
            $inCohort = $closes[$primary] !== null;
            $deltaX = $arms['fixed']['x'] - $arms['trail']['x'];

            if ($deltaX < 0) {
                throw new LogicException("{$ticker}: Δx < 0 en la entrada {$i}.");
            }

            $d = null;
            $d10 = null;
            $g = null;

            if ($inCohort) {
                $d = $arms['trail']['v'][$primary] - $arms['fixed']['v'][$primary];
                $d10 = $arms['trail10']['v'][$primary] - $arms['fixed']['v'][$primary];
                $g = (($closes[$primary]['close'] / $open - 1) * 100) / $primary;

                // Assert 5: si NINGUN brazo sale en entry+H o antes, D es 0 exacto.
                $fixedExitsByH = $arms['fixed']['exit_index'] <= $entryIndex + $primary;
                $trailExitsByH = $arms['trail']['exit_index'] <= $entryIndex + $primary;

                if (!$fixedExitsByH && !$trailExitsByH && $d !== 0.0) {
                    throw new LogicException("{$ticker}: D != 0 sin que ningun brazo salga antes de H (entrada {$i}).");
                }
            }

            $rows[] = [
                'ticker' => strtoupper($ticker),
                'entry_date' => $canonical['entry_date'],
                'entry_index' => $entryIndex,
                'entry_open' => $open,
                'last_index' => $lastIndex,
                'closes' => $closes,
                'in_cohort' => $inCohort,
                'g' => $g,
                'delta_x' => $deltaX,
                'd' => $d,
                'd10' => $d10,
                // Salida trailing ESTRICTAMENTE anterior a la del fijo y <= H (cuota de la penalizacion de reentrada).
                'reentry' => $arms['trail']['exit_index'] < $arms['fixed']['exit_index']
                    && $arms['trail']['exit_index'] <= $entryIndex + $primary,
                'bear' => $bearRegime !== null ? $bearRegime($canonical['entry_date']) : null,
                'baseline_return' => $canonical['baseline_return'],
                'baseline_exit_date' => $canonical['baseline_exit_date'],
                'stop_raises' => $trail['stop_raises'],
                'initial_stop' => $trail['initial_stop'],
                'arms' => $arms,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $trade
     * @param array<int, array{date: string, close: float}|null> $closes
     * @param list<HistoricalQuote> $history
     * @return array{exit_index: int, exit_date: string, exit_price_raw: float, managed_return: float, pending: bool, exit_reason: string, x: int, v: array<int, ?float>, mfe: float}
     */
    private function arm(array $trade, int $entryIndex, float $open, array $closes, array $history): array
    {
        $exitIndex = (int) $trade['exit_index'];
        $v = [];

        foreach (self::HORIZONS as $horizon) {
            $v[$horizon] = match (true) {
                $closes[$horizon] === null => null,
                $exitIndex <= $entryIndex + $horizon => (float) $trade['managed_return'],
                default => $this->netReturn($open, $closes[$horizon]['close']),
            };
        }

        // MFE a H primario: maximo cierre en [entry, min(exit - 1, entry + H)]
        // como retorno bruto sobre el open de entrada; rango vacio = 0.
        $mfeEnd = min($exitIndex - 1, $entryIndex + self::PRIMARY_HORIZON);
        $mfe = 0.0;

        if ($mfeEnd >= $entryIndex) {
            $maxClose = $history[$entryIndex]->getClose();

            for ($j = $entryIndex + 1; $j <= $mfeEnd; $j++) {
                $maxClose = max($maxClose, $history[$j]->getClose());
            }

            $mfe = ($maxClose / $open - 1) * 100;
        }

        return [
            'exit_index' => $exitIndex,
            'exit_date' => (string) $trade['exit_date'],
            'exit_price_raw' => (float) $trade['exit_price_raw'],
            'managed_return' => (float) $trade['managed_return'],
            'pending' => (bool) $trade['pending'],
            'exit_reason' => (string) $trade['exit_reason'],
            'x' => min($exitIndex - $entryIndex, self::PRIMARY_HORIZON),
            'v' => $v,
            'mfe' => $mfe,
        ];
    }
}
