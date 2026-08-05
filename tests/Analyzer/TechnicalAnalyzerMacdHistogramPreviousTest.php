<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Analyzer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Cubre el nuevo campo TechnicalSnapshot::macdHistogramPrevious (v2.53, ver
 * versions.md): el histograma MACD una sesion antes de la ultima definida,
 * usado para detectar un cruce alcista reciente en
 * TechnicalScoreAnalyzer::technical().
 *
 * Ambos indicadores (MACD linea y señal) son estrictamente causales: se
 * calculan hacia adelante a partir de los valores ya vistos, sin mirar al
 * futuro. Por tanto el histograma en el indice i calculado sobre la serie
 * completa coincide con el histograma en ese mismo indice i calculado sobre
 * la serie truncada justo despues de i. Este test aprovecha esa propiedad
 * en vez de reimplementar la formula de EMA/MACD: en lugar de reproducir
 * los calculos internos, compara analyze() sobre el historico completo
 * contra analyze() sobre el mismo historico con una sesion menos.
 */
final class TechnicalAnalyzerMacdHistogramPreviousTest extends TestCase
{
    public function testMacdHistogramPreviousCoincideConElHistogramaDeUnaSesionAntes(): void
    {
        $analyzer = new TechnicalAnalyzer();
        $quotes = $this->buildSyntheticQuotes(60);

        $snapshot = $analyzer->analyze($quotes);
        $snapshotOneDayLess = $analyzer->analyze(array_slice($quotes, 0, 59));

        self::assertNotNull($snapshot->getMacdHistogram());
        self::assertNotNull($snapshot->getMacdHistogramPrevious());
        self::assertNotNull($snapshotOneDayLess->getMacdHistogram());

        self::assertEqualsWithDelta(
            $snapshotOneDayLess->getMacdHistogram(),
            $snapshot->getMacdHistogramPrevious(),
            0.0000001
        );

        // Ademas, el "actual" y el "anterior" no deben coincidir entre si
        // en una serie con variacion real: si lo hicieran, el test no
        // estaria comprobando nada distinto de un simple "no es null".
        self::assertNotEqualsWithDelta(
            $snapshot->getMacdHistogram(),
            $snapshot->getMacdHistogramPrevious(),
            0.0000001
        );
    }

    /**
     * Con exactamente 34 sesiones el histograma MACD queda definido solo en
     * la ultima (ver umbral de TechnicalAnalyzer::macdFromEma(): EMA26
     * necesita 26 sesiones, la señal (EMA9 sobre el MACD ya definido)
     * necesita 9 valores mas => 26 + 9 - 1 = 34 sesiones para el primer
     * histograma definido). Con solo un punto definido no hay "sesion
     * anterior" dentro de la serie: macdHistogramPrevious debe ser null
     * aunque macdHistogram si este definido.
     */
    public function testMacdHistogramPreviousEsNullConSoloUnPuntoDefinido(): void
    {
        $analyzer = new TechnicalAnalyzer();
        $quotes = $this->buildSyntheticQuotes(34);

        $snapshot = $analyzer->analyze($quotes);

        self::assertNotNull($snapshot->getMacdHistogram());
        self::assertNull($snapshot->getMacdHistogramPrevious());
    }

    /**
     * @return list<HistoricalQuote>
     */
    private function buildSyntheticQuotes(int $days): array
    {
        $quotes = [];
        $date = new DateTimeImmutable('2026-01-01');
        $close = 100.0;

        // Serie deterministica con variacion real (no monotona, no
        // constante) para que EMA12/EMA26/MACD/señal tomen valores
        // distintos sesion a sesion: combina una tendencia suave con una
        // oscilacion, sin depender de numeros aleatorios.
        for ($i = 0; $i < $days; $i++) {
            $close = 100.0 + ($i * 0.15) + sin($i / 3.0) * 4.0;
            $quotes[] = new HistoricalQuote($date, $close, $close + 1.0, $close - 1.0, $close, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }
}
