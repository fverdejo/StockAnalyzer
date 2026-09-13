<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Services;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\ScoreCalculator;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Config\RiskLevelsConfig;
use StockAnalyzer\Models\HistoricalQuote;
use StockAnalyzer\Services\BacktestingService;
use StockAnalyzer\Services\RiskLevelsCalculator;

/**
 * `BacktestingService::replayTimeline()` (Entrega 3 de
 * `PLAN_VALIDACION_MOTOR_ASTRA_2026-09-10.md`): a diferencia de
 * `sampleHistory()`/`sampleOnCalendar()`, nunca mira el futuro ni descarta
 * ninguna fecha -- reproduce lo que la aplicacion en vivo diria cada
 * `$step` sesiones, para alimentar `Services\PolicyReplaySimulator`.
 *
 * No se verifica aqui la recomendacion EXACTA que da el score real (eso ya
 * lo cubren los tests de `ScoreCalculator`/`TechnicalScoreAnalyzer`): se
 * comprueba el MECANISMO propio de este metodo -- cadencia, entrada a la
 * apertura siguiente (P0.1), y que `stop_loss` solo aparece junto a un BUY.
 */
final class BacktestingServiceReplayTimelineTest extends TestCase
{
    private function service(): BacktestingService
    {
        return new BacktestingService(
            new PerTickerHistoryProvider(SyntheticStock::create(), ['AAA' => $this->risingHistory()]),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0))
        );
    }

    /**
     * 200 velas: 80 planas (para que `$minimumLookback` tenga margen) y
     * 120 en tendencia alcista sostenida -- suficiente para que el score
     * tecnico puntue BUY en algun tramo, sin necesitar calibrar un cierre
     * exacto (a diferencia de los tests de `BacktestingServiceCrossSectionalTest`,
     * aqui no hace falta un `forward_return` exacto: `replayTimeline()`
     * nunca mira el futuro).
     *
     * @return list<HistoricalQuote>
     */
    private function risingHistory(): array
    {
        $quotes = [];
        $date = new DateTimeImmutable('2024-01-01');
        $close = 100.0;

        for ($i = 0; $i < 80; $i++) {
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        for ($i = 0; $i < 120; $i++) {
            $close += 0.6;
            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    public function testLosPuntosDelRecorridoEstanEspaciadosPorStepDesdeElMinimoLookback(): void
    {
        $timeline = $this->service()->replayTimeline('AAA', step: 10);

        self::assertNotEmpty($timeline);
        self::assertSame(80, $timeline[0]['index']);

        for ($i = 1; $i < count($timeline); $i++) {
            self::assertSame($timeline[$i - 1]['index'] + 10, $timeline[$i]['index']);
        }
    }

    /**
     * P0.1: la entrada de cada punto es la apertura de la sesion
     * SIGUIENTE a la fecha de señal, no su propio cierre.
     */
    public function testLaEntradaDeCadaPuntoEsLaAperturaDeLaSesionSiguiente(): void
    {
        $history = $this->risingHistory();
        $timeline = $this->service()->replayTimeline('AAA', step: 10);

        foreach ($timeline as $point) {
            if ($point['index'] + 1 < count($history)) {
                self::assertSame($history[$point['index'] + 1]->getOpen(), $point['entry_price']);
            } else {
                self::assertNull($point['entry_price'], 'La ultima vela disponible no tiene sesion siguiente donde entrar.');
            }
        }
    }

    public function testStopLossSoloApareceJuntoAUnaRecomendacionBuy(): void
    {
        $timeline = $this->service()->replayTimeline('AAA', step: 5);

        $buyPoints = array_filter($timeline, static fn (array $point): bool => $point['recommendation'] === 'BUY');
        self::assertNotEmpty($buyPoints, 'La tendencia alcista sostenida del fixture debe producir al menos un BUY.');

        foreach ($timeline as $point) {
            if ($point['recommendation'] === 'BUY') {
                self::assertIsFloat($point['stop_loss']);
                self::assertLessThan($this->historyCloseAt($point['index']), $point['stop_loss']);
            } else {
                self::assertNull($point['stop_loss']);
            }
        }
    }

    private function historyCloseAt(int $index): float
    {
        return $this->risingHistory()[$index]->getClose();
    }

    /**
     * Sin `FundamentalsHistoryRepository` conectado (mismo patron que el
     * resto de tests de `BacktestingService` sin base de datos), el
     * cambio fundamental nunca se evalua -- `null` explicito, no un
     * intento fallido.
     */
    public function testSinRepositorioDeFundamentalesConectadoElCambioFundamentalEsNulo(): void
    {
        $timeline = $this->service()->replayTimeline('AAA', step: 20);

        foreach ($timeline as $point) {
            self::assertNull($point['fundamental_change']);
        }
    }

    public function testUnAsOfExplicitoCongelaElRecorridoIgualQueEnRunCrossSectional(): void
    {
        $history = $this->risingHistory();
        $cutoff = $history[150]->getDate();

        $timeline = $this->service()->replayTimeline('AAA', step: 10, asOf: $cutoff);

        self::assertLessThanOrEqual(150, end($timeline)['index']);
    }
}
