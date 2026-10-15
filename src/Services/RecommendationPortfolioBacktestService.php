<?php

declare(strict_types=1);

namespace StockAnalyzer\Services;

use DateTimeImmutable;
use Throwable;

/**
 * Orquesta el backtest literal de recomendaciones sobre un capital comun.
 *
 * BUY abre, SELL/STRONG SELL inicia el cierre y HOLD no crea ordenes. Las salidas
 * pendientes persisten sujetas a capacidad. Todas las decisiones
 * se calculan con replayTimeline() al cierre y se ejecutan desde el open siguiente.
 */
final class RecommendationPortfolioBacktestService
{
    public function __construct(
        private readonly BacktestingService $backtesting,
        private readonly RecommendationPortfolioDatasetBuilder $datasetBuilder = new RecommendationPortfolioDatasetBuilder(),
        private readonly PortfolioBacktestSimulator $simulator = new PortfolioBacktestSimulator()
    ) {
    }

    /**
     * @param list<string> $tickers
     * @return array<string,mixed>
     */
    public function run(
        array $tickers,
        int $step = 1,
        ?DateTimeImmutable $asOf = null,
        ?string $indexCode = null,
        string $benchmarkTicker = 'SPY'
    ): array {
        $histories = [];
        $timelines = [];
        $errors = [];
        $benchmarkTicker = strtoupper(trim($benchmarkTicker));
        $benchmarkHistory = [];
        $benchmarkError = null;

        foreach ($tickers as $ticker) {
            $ticker = strtoupper(trim($ticker));

            if ($ticker === '') {
                continue;
            }

            try {
                $histories[$ticker] = $this->backtesting->historyFor($ticker, $asOf);
                $timelines[$ticker] = $this->backtesting->replayTimeline($ticker, $step, $asOf, $indexCode);
            } catch (Throwable $exception) {
                unset($histories[$ticker]);
                $errors[$ticker] = $exception->getMessage();
            }
        }

        if ($benchmarkTicker !== '') {
            try {
                $benchmarkHistory = $histories[$benchmarkTicker]
                    ?? $this->backtesting->historyFor($benchmarkTicker, $asOf);
            } catch (Throwable $exception) {
                $benchmarkError = $exception->getMessage();
            }
        }

        $dataset = $this->datasetBuilder->build($histories, $timelines, $benchmarkHistory);
        $simulation = $this->simulator->simulate($dataset['days']);
        unset($dataset['days']);
        $strictPointInTime = $dataset['strict_point_in_time_recommendations'];
        $pointInTimeCoverage = $dataset['fundamentals_point_in_time_pct'] ?? null;
        $excludedMissingPointInTime = $dataset['recommendations_excluded_by_missing_pit'];

        return array_merge(
            [
                'policy' => 'BUY abre; SELL/STRONG SELL inicia cierre; HOLD no crea ordenes; salidas pendientes hasta completar; ejecucion desde el open siguiente',
                'execution_warning' => 'Capacidad diaria compartida de compras, ventas y stops sobre volumen previo; coste fijo por lado. ADV no garantiza liquidez en apertura ni modela impacto por participacion.',
                'step' => $step,
                'as_of' => $asOf?->format('Y-m-d'),
                'index_code' => $indexCode === null ? null : strtoupper($indexCode),
                'benchmark_ticker' => $benchmarkTicker === '' ? null : $benchmarkTicker,
                'benchmark_error' => $benchmarkError,
                'tickers_requested' => count($tickers),
                'tickers_simulated' => count($histories),
                'calendar_days' => count($simulation['equity_curve']),
                'errors' => $errors,
                'membership_warning' => $indexCode === null
                    ? 'Sin indice point-in-time: la seleccion de tickers conserva sesgo de supervivencia.'
                    : 'Membresia point-in-time aplicada; revisar antiguos miembros sin historico utilizable.',
                'point_in_time_warning' => match (true) {
                    $strictPointInTime && $pointInTimeCoverage === 100.0
                        => 'Modo estricto: todas las recomendaciones usan snapshots fundamentales; dividendGrowth5y sigue sin vintage historico.',
                    $strictPointInTime
                        => "Modo estricto: $excludedMissingPointInTime recomendaciones sin snapshot point-in-time fueron excluidas.",
                    $pointInTimeCoverage === 100.0
                        => 'Snapshots fundamentales completos; dividendGrowth5y sigue sin vintage historico.',
                    default
                        => 'Modo exploratorio: la curva puede usar fundamentales actuales como fallback.',
                },
            ],
            $dataset,
            $simulation
        );
    }
}
