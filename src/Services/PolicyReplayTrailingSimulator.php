<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use LogicException;
use StockAnalyzer\Config\BacktestingConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\Concerns\StopLossExitCalculator;

/**
 * Variante EXPERIMENTAL de `PolicyReplaySimulator` con un stop que sube
 * (trailing), solo para el piloto predeclarado por `gestor-riesgo`
 * (`2026-09-20`, ver versions.md). NO es la politica de produccion:
 * `AlertService` adopta el stop UNA vez y solo lo lee, y este simulador no
 * cambia eso -- responde unicamente a "si el stop subiera, ¿cambiaria la
 * exposicion y el resultado de una politica sin horizonte?".
 *
 * Regla (A), la unica probada, sin parametros nuevos: en cada punto del
 * timeline con la operacion abierta, `stopActivo = max(stopActivo,
 * candidate_stop)`, donde `candidate_stop` es el stop que
 * `RiskLevelsCalculator::compute()` daria HOY (ATR14 x multiplicador) con los
 * datos hasta esa sesion inclusive ("el stop esta donde lo pondria si
 * entrara hoy, y nunca baja").
 *
 * Sin look-ahead: el tramo hasta `point['index']` INCLUSIVE se recorre con
 * el stop viejo; el nuevo rige desde la apertura de `index + 1`. La
 * cadencia es la del timeline (`step` sesiones), mas lenta que una
 * actualizacion diaria -- alarga la exposicion, asi que un resultado
 * favorable en el piloto es conservador respecto a una cadencia diaria.
 *
 * Mismas entradas que el brazo fijo, sin reentradas propias: la simulacion
 * PARTE de `PolicyReplaySimulator::replay()` y solo re-recorre la SALIDA de
 * cada operacion. Como el stop del trailing es >= el fijo en todo momento,
 * sale el mismo dia o antes -- nunca se solapa con la entrada siguiente, y
 * la unica variable que cambia es la regla de salida. Los campos del
 * comparador (`baseline_*`) dependen solo de la entrada y se copian tal cual.
 *
 * Consecuencia a tener en cuenta al interpretar: al no simular reentradas
 * el trailing NO paga el coste (2 x coste) ni el whipsaw de volver a entrar
 * tras salir antes.
 *
 * Sin candidato calculable (`candidate_stop` nulo o <= 0, o campo ausente) el
 * stop activo se mantiene: nunca se elimina ni se baja. Se cuentan esas
 * revisiones (`reviews_without_candidate`) para poder marcar la
 * contabilidad como dudosa si superan el 5%.
 *
 * Con `$trailingEnabled = false` el mismo re-recorrido reproduce EXACTAMENTE
 * el brazo fijo (verificacion de contabilidad predeclarada).
 */
final class PolicyReplayTrailingSimulator
{
    use StopLossExitCalculator;

    public function __construct(
        private readonly PolicyReplaySimulator $fixedSimulator = new PolicyReplaySimulator(),
        private readonly BacktestingConfig $backtestingConfig = new BacktestingConfig()
    ) {
    }

    protected function getCostRate(): float
    {
        return $this->backtestingConfig->getCostRate();
    }

    /**
     * Misma forma que `PolicyReplaySimulator::replay()` (para poder pasarlo
     * a `PolicyReplayStatistics::summarize()` tal cual), mas campos
     * extra por operacion y dos contadores de revisiones.
     *
     * @param list<array{date: string, index: int, recommendation: string, stop_loss: ?float, candidate_stop?: ?float, fundamental_change: ?\StockAnalyzer\DTO\FundamentalChangeAssessment, entry_price: ?float, eligible: bool}> $timeline
     * @param list<HistoricalQuote> $history
     * @return array{ticker: string, trades: list<array<string, mixed>>, entries_total: int, entries_closed: int, entries_pending: int, candidates_excluded_by_membership: int, reviews_total: int, reviews_without_candidate: int}
     */
    public function replay(string $ticker, array $timeline, array $history, bool $trailingEnabled = true): array
    {
        $fixed = $this->fixedSimulator->replay($ticker, $timeline, $history);

        $initialStopByEntryIndex = [];

        foreach ($timeline as $point) {
            // La entrada del brazo fijo es la apertura de la sesion SIGUIENTE
            // a la del punto que la acepta, con el `stop_loss` de ESE punto.
            $initialStopByEntryIndex[$point['index'] + 1] = $point['stop_loss'];
        }

        $trades = [];
        $reviewsTotal = 0;
        $reviewsWithoutCandidate = 0;

        foreach ($fixed['trades'] as $fixedTrade) {
            $initialStop = $initialStopByEntryIndex[$fixedTrade['entry_index']] ?? null;

            if ($initialStop === null) {
                throw new LogicException(sprintf(
                    'No se encontro el stop de entrada de %s (indice %d) en el timeline: el brazo fijo y el timeline no coinciden.',
                    $ticker,
                    $fixedTrade['entry_index']
                ));
            }

            $trades[] = $this->retrace(
                $fixedTrade,
                (float) $initialStop,
                $timeline,
                $history,
                $trailingEnabled,
                $reviewsTotal,
                $reviewsWithoutCandidate
            );
        }

        return [
            'ticker' => $fixed['ticker'],
            'trades' => $trades,
            'entries_total' => count($trades),
            'entries_closed' => count(array_filter($trades, static fn (array $trade): bool => !$trade['pending'])),
            'entries_pending' => count(array_filter($trades, static fn (array $trade): bool => $trade['pending'])),
            'candidates_excluded_by_membership' => $fixed['candidates_excluded_by_membership'],
            'reviews_total' => $reviewsTotal,
            'reviews_without_candidate' => $reviewsWithoutCandidate,
        ];
    }

    /**
     * Invariantes de contabilidad predeclarados (gestor-riesgo,
     * `2026-09-20`), por operacion: la salida del trailing nunca es
     * posterior a la del fijo y su stop final nunca queda por debajo del
     * inicial. Devuelve un mensaje por cada violacion (vacio = todo bien).
     *
     * @param array{ticker: string, trades: list<array<string, mixed>>} $trailingReplay
     * @return list<string>
     */
    public function accountingViolations(array $trailingReplay): array
    {
        $violations = [];

        foreach ($trailingReplay['trades'] as $trade) {
            $label = sprintf('%s %s', $trailingReplay['ticker'], $trade['entry_date']);

            if ($trade['exit_index'] > $trade['fixed_exit_index']) {
                $violations[] = "{$label}: la salida del trailing (indice {$trade['exit_index']}) es POSTERIOR a la del fijo ({$trade['fixed_exit_index']}).";
            }

            if ($trade['final_stop'] < $trade['initial_stop']) {
                $violations[] = "{$label}: el stop final del trailing ({$trade['final_stop']}) es inferior al inicial ({$trade['initial_stop']}).";
            }
        }

        return $violations;
    }

    /**
     * @param array{entry_date: string, entry_index: int, entry_price: float, exit_date: string, exit_index: int, exit_price: float, exit_reason: string, pending: bool, holding_days: int, managed_return: float, baseline_return: ?float, baseline_exit_date: ?string, baseline_pending: bool, revisar_tesis_events: int} $fixedTrade
     * @param list<array<string, mixed>> $timeline
     * @param list<HistoricalQuote> $history
     * @return array<string, mixed>
     */
    private function retrace(
        array $fixedTrade,
        float $initialStop,
        array $timeline,
        array $history,
        bool $trailingEnabled,
        int &$reviewsTotal,
        int &$reviewsWithoutCandidate
    ): array {
        $entryIndex = $fixedTrade['entry_index'];
        // Sin redondear (el trade del fijo lo trae a 4 decimales): asi el
        // retorno reproduce exactamente el del brazo fijo.
        $entryPrice = $history[$entryIndex]->getOpen();
        $lastIndex = count($history) - 1;
        $activeStop = $initialStop;
        $cursor = $entryIndex;
        $raises = 0;
        $breach = null;

        foreach ($timeline as $point) {
            // Solo puntos de revision POSTERIORES a la entrada: el punto que
            // origino la operacion tiene indice `entryIndex - 1`.
            if ($point['index'] < $entryIndex) {
                continue;
            }

            $to = min($point['index'], $lastIndex);
            $breach = $this->walkForStopBreach($history, $cursor, $to, $activeStop);

            if ($breach !== null) {
                break;
            }

            $cursor = $to + 1;
            $reviewsTotal++;
            $candidate = $point['candidate_stop'] ?? null;

            if ($candidate === null || $candidate <= 0.0) {
                $reviewsWithoutCandidate++;

                continue;
            }

            if ($trailingEnabled && $candidate > $activeStop) {
                $activeStop = (float) $candidate;
                $raises++;
            }
        }

        if ($breach === null) {
            // Tramo final hasta el ultimo cierre disponible: el ultimo punto
            // del timeline puede quedar `step` sesiones por debajo del final
            // real del historico (mismo motivo que en el brazo fijo).
            $breach = $this->walkForStopBreach($history, $cursor, $lastIndex, $activeStop);
        }

        $pending = $breach === null;
        $exitIndex = $pending ? $lastIndex : $breach[0];
        $exitPrice = $pending ? $history[$lastIndex]->getClose() : $breach[1];

        $maxClose = $entryPrice;
        $mfeEnd = $pending ? $exitIndex : $exitIndex - 1;

        for ($i = $entryIndex; $i <= $mfeEnd; $i++) {
            $maxClose = max($maxClose, $history[$i]->getClose());
        }

        return [
            'entry_date' => $fixedTrade['entry_date'],
            'entry_index' => $entryIndex,
            'entry_price' => $fixedTrade['entry_price'],
            'exit_date' => $history[$exitIndex]->getDate()->format('Y-m-d'),
            'exit_index' => $exitIndex,
            'exit_price' => round($exitPrice, 4),
            'exit_reason' => $pending ? 'pending_at_cutoff' : 'stop_loss',
            'pending' => $pending,
            'holding_days' => $exitIndex - $entryIndex,
            'managed_return' => $this->netReturn($entryPrice, $exitPrice),
            // Dependen solo de la ENTRADA, no de la salida gestionada.
            'baseline_return' => $fixedTrade['baseline_return'],
            'baseline_exit_date' => $fixedTrade['baseline_exit_date'],
            'baseline_pending' => $fixedTrade['baseline_pending'],
            // El trailing no reevalua el diagnostico fundamental (no afecta
            // a la salida): 0, no el conteo del brazo fijo.
            'revisar_tesis_events' => 0,
            'initial_stop' => $initialStop,
            'final_stop' => $activeStop,
            'stop_raises' => $raises,
            'mfe_close_pct' => round(($maxClose / $entryPrice - 1) * 100, 2),
            'fixed_exit_index' => $fixedTrade['exit_index'],
            'fixed_exit_price' => $fixedTrade['exit_price'],
            'fixed_exit_reason' => $fixedTrade['exit_reason'],
            'fixed_managed_return' => $fixedTrade['managed_return'],
        ];
    }
}
