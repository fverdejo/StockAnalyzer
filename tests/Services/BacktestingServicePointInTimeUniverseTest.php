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
 * Cubre el universo point-in-time de `BacktestingService::runCrossSectional()`
 * (roadmap.md, "Segundo bloque" punto 5, 2026-09-02): el criterio de salida
 * explicito del plan es que un test falle si se cuela un componente que
 * entro al indice DESPUES de la fecha D, o si se conserva uno que ya habia
 * salido ANTES de D. Los dos casos estan cubiertos abajo, mas la
 * compatibilidad hacia atras (sin indexCode/sin repositorio conectado, el
 * comportamiento no cambia respecto a BacktestingServiceCrossSectionalTest).
 */
final class BacktestingServicePointInTimeUniverseTest extends TestCase
{
    private const HORIZON_DAYS = 5;
    private const STEP = 5;

    /**
     * 91 velas alcistas (81 de arranque + dos tramos de 5) con dos fechas de
     * senal, mismo esqueleto que BacktestingServiceCrossSectionalTest:
     * indice 80 -> 2024-03-21, indice 85 -> 2024-03-26.
     *
     * @return list<HistoricalQuote>
     */
    private function history(): array
    {
        $quotes = [];
        $close = 100.0;
        $date = new DateTimeImmutable('2024-01-01');

        for ($i = 0; $i < 91; $i++) {
            if ($i > 0) {
                $close += 0.5;
            }

            $quotes[] = new HistoricalQuote($date, $close, $close + 0.5, $close - 0.5, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }

    /**
     * @param list<string> $tickers
     * @param list<array{ticker: string, indexCode: string, startDate: ?string, endDate: ?string}> $memberships
     */
    private function service(array $tickers, array $memberships): BacktestingService
    {
        $historiesByTicker = [];

        foreach ($tickers as $ticker) {
            $historiesByTicker[$ticker] = $this->history();
        }

        return new BacktestingService(
            new PerTickerHistoryProvider(SyntheticStock::create(), $historiesByTicker),
            new TechnicalAnalyzer(),
            new ScoreCalculator(),
            new RiskLevelsCalculator(new RiskLevelsConfig(2.5, 2.0)),
            indexMembership: new ArrayIndexMembershipChecker($memberships)
        );
    }

    /**
     * AAA y CCC son miembros del indice durante todo el recorrido (baseline,
     * garantizan amplitud suficiente en las dos fechas). SALIENTE deja el
     * indice el 2024-03-22, un dia despues de la primera fecha de senal
     * (2024-03-21, si cuenta) y ANTES de la segunda (2024-03-26, no debe
     * contar): debe aparecer en la primera fecha y desaparecer en la
     * segunda.
     */
    public function testComponenteQueSalioDelIndiceAntesDeLaFechaDSeExcluye(): void
    {
        $result = $this->service(
            ['AAA', 'CCC', 'SALIENTE'],
            [
                ['ticker' => 'AAA', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'CCC', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'SALIENTE', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => '2024-03-22'],
            ]
        )->runCrossSectional(['AAA', 'CCC', 'SALIENTE'], self::HORIZON_DAYS, self::STEP, 1, 'full', 'GSPC');

        self::assertTrue($result['point_in_time_universe']);
        self::assertSame('GSPC', $result['index_code']);
        self::assertSame(2, $result['dates_evaluated']);

        $byDate = [];
        foreach ($result['dates'] as $day) {
            $byDate[$day['date']] = $day;
        }

        // 2024-03-21: SALIENTE todavia era miembro (salio el 22), asi que
        // cuenta en la amplitud del universo de esa fecha.
        self::assertSame(3, $byDate['2024-03-21']['universe_size']);
        // 2024-03-26: SALIENTE ya no era miembro, la amplitud baja a 2 y no
        // debe aparecer entre los tickers evaluados de esa fecha.
        self::assertSame(2, $byDate['2024-03-26']['universe_size']);

        self::assertSame(1, $result['samples_dropped_not_member']);
        // 3 fechas*tickers totales - 1 descartada = 5 muestras conservadas.
        self::assertSame(5, $result['samples_kept']);
    }

    /**
     * Espejo del test anterior: ENTRANTE entra al indice el 2024-03-25,
     * DESPUES de la primera fecha de senal (2024-03-21, no debe contar) y
     * ANTES de la segunda (2024-03-26, si debe contar).
     */
    public function testComponenteQueEntroAlIndiceDespuesDeLaFechaDSeExcluye(): void
    {
        $result = $this->service(
            ['AAA', 'CCC', 'ENTRANTE'],
            [
                ['ticker' => 'AAA', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'CCC', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'ENTRANTE', 'indexCode' => 'GSPC', 'startDate' => '2024-03-25', 'endDate' => null],
            ]
        )->runCrossSectional(['AAA', 'CCC', 'ENTRANTE'], self::HORIZON_DAYS, self::STEP, 1, 'full', 'GSPC');

        $byDate = [];
        foreach ($result['dates'] as $day) {
            $byDate[$day['date']] = $day;
        }

        self::assertSame(2, $byDate['2024-03-21']['universe_size']);
        self::assertSame(3, $byDate['2024-03-26']['universe_size']);
        self::assertSame(1, $result['samples_dropped_not_member']);
        self::assertSame(5, $result['samples_kept']);
    }

    /**
     * Sin indexCode (valor por defecto), el comportamiento no cambia: ningun
     * ticker se descarta por membresia aunque el servicio tenga un
     * IndexMembershipCheckerInterface conectado -- mismo criterio de
     * "opcional, no rompe nada existente" que el resto de dependencias
     * opcionales del constructor.
     */
    public function testSinIndexCodeNoActivaElFiltroDeMembresia(): void
    {
        $result = $this->service(
            ['AAA', 'CCC', 'SALIENTE'],
            [
                ['ticker' => 'AAA', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'CCC', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => null],
                ['ticker' => 'SALIENTE', 'indexCode' => 'GSPC', 'startDate' => null, 'endDate' => '2024-03-22'],
            ]
        )->runCrossSectional(['AAA', 'CCC', 'SALIENTE'], self::HORIZON_DAYS, self::STEP, 1);

        self::assertFalse($result['point_in_time_universe']);
        self::assertNull($result['index_code']);
        self::assertSame(0, $result['samples_dropped_not_member']);
        self::assertSame(6, $result['samples_kept']);

        foreach ($result['dates'] as $day) {
            self::assertSame(3, $day['universe_size']);
        }
    }
}
