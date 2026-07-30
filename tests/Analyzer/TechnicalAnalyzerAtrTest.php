<?php

declare(strict_types=1);

namespace StockAnalyzer\Tests\Analyzer;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use StockAnalyzer\Analyzer\TechnicalAnalyzer;
use StockAnalyzer\Models\HistoricalQuote;

/**
 * Cubre el cambio de ATR14 de media simple a suavizado de Wilder (ver
 * versions.md): el mismo historico debe dar un ATR14 distinto segun la
 * formula, y el valor devuelto debe ser el de Wilder, no el de una SMA de
 * los ultimos 14 true ranges.
 *
 * Historico construido a mano con 20 sesiones (por encima del periodo 14,
 * como exige TechnicalAnalyzer::atr()): 9 sesiones con true range
 * constante de 2, un "gap" de un solo dia con true range 31, y 9 sesiones
 * mas con true range constante de 2. Con esos true ranges:
 *
 * - SMA de los ultimos 14 (incluye el gap) = 4,0714285714...
 * - Wilder (siembra con la SMA de los 14 primeros, despues recursivo)
 *   = 3,4300345997...
 *
 * Los dos valores se calcularon de forma independiente (ver el propio
 * comentario) para no acoplar el test a la implementacion interna del
 * metodo bajo prueba.
 */
final class TechnicalAnalyzerAtrTest extends TestCase
{
    private const EXPECTED_ATR14_WILDER = 3.4300345997416;
    private const SMA_OF_LAST_14 = 4.0714285714286;

    public function testAtr14UsaSuavizadoDeWilderNoMediaSimple(): void
    {
        $analyzer = new TechnicalAnalyzer();
        $quotes = $this->buildQuotesWithGapDay();

        $snapshot = $analyzer->analyze($quotes);

        self::assertNotNull($snapshot->getAtr14());
        self::assertEqualsWithDelta(self::EXPECTED_ATR14_WILDER, $snapshot->getAtr14(), 0.0001);
        self::assertNotEqualsWithDelta(self::SMA_OF_LAST_14, $snapshot->getAtr14(), 0.001);
    }

    /**
     * Umbral exacto del guardarraya `$count <= $period` en
     * TechnicalAnalyzer::atr(): con exactamente 14 cotizaciones (== period)
     * no hay ni un solo true range completo tras descontar el dia base, asi
     * que debe devolver null, igual que con menos historico.
     */
    public function testAtr14EsNullConHistoricoIgualAlPeriodo(): void
    {
        $analyzer = new TechnicalAnalyzer();
        $quotes = $this->buildQuotesWithLinearTrueRanges(13); // 1 dia base + 13 = 14 en total

        $snapshot = $analyzer->analyze($quotes);

        self::assertNull($snapshot->getAtr14());
    }

    /**
     * Umbral exacto del guardarraya, justo por encima: con period+1 (15)
     * cotizaciones hay exactamente 14 true ranges, que es toda la ventana
     * de la "siembra" de Wilder (SMA de los primeros $period true ranges).
     * El bucle de suavizado recursivo (`for index = period; ...`) no llega
     * a ejecutarse ni una sola vez, asi que el ATR14 resultante debe
     * coincidir con la media aritmetica simple de esos 14 true ranges, sin
     * ninguna diferencia frente a una SMA en este caso limite concreto.
     *
     * True ranges construidos como high-low puro (2, 4, 6, ..., 28, es
     * decir 2*i para i=1..14), con el cierre anterior siempre mas cerca
     * del rango high-low que del extremo, para que domine siempre
     * `$highs[$index] - $lows[$index]` en la formula de true range.
     * Media = (2+4+...+28)/14 = 210/14 = 15.0 exacto.
     */
    public function testAtr14ConHistoricoMinimoValidoEsSoloLaSiembraSma(): void
    {
        $analyzer = new TechnicalAnalyzer();
        $quotes = $this->buildQuotesWithLinearTrueRanges(14); // 1 dia base + 14 = 15 en total

        $snapshot = $analyzer->analyze($quotes);

        self::assertEqualsWithDelta(15.0, $snapshot->getAtr14(), 0.0001);
    }

    /**
     * @return list<HistoricalQuote>
     */
    private function buildQuotesWithLinearTrueRanges(int $days): array
    {
        $quotes = [];
        $date = new DateTimeImmutable('2026-01-01');

        // Dia base: solo aporta el cierre de referencia (100) para el
        // primer true range.
        $quotes[] = new HistoricalQuote($date, 100.0, 100.0, 100.0, 100.0, 1_000_000);

        for ($i = 1; $i <= $days; $i++) {
            $date = $date->modify('+1 day');
            // high-low = 2*i, siempre mayor que |high-100| = |low-100| = i,
            // asi que el true range de este dia es exactamente 2*i.
            $quotes[] = new HistoricalQuote($date, 100.0, 100.0 + $i, 100.0 - $i, 100.0, 1_000_000);
        }

        return $quotes;
    }

    /**
     * @return list<HistoricalQuote>
     */
    private function buildQuotesWithGapDay(): array
    {
        $quotes = [];
        $date = new DateTimeImmutable('2026-01-01');

        // Dia base (index 0): su high/low no entra en ningun true range,
        // solo su close sirve de referencia para el primer true range.
        $quotes[] = new HistoricalQuote($date, 100.0, 101.0, 99.0, 100.0, 1_000_000);
        $date = $date->modify('+1 day');

        // Indices 1-9: 9 sesiones con true range constante de 2 (respecto
        // al cierre anterior de 100).
        for ($i = 0; $i < 9; $i++) {
            $quotes[] = new HistoricalQuote($date, 100.0, 101.0, 99.0, 100.0, 1_000_000);
            $date = $date->modify('+1 day');
        }

        // Indice 10: dia con gap grande (true range 31 respecto al cierre
        // anterior de 100).
        $quotes[] = new HistoricalQuote($date, 130.0, 131.0, 129.0, 130.0, 1_000_000);
        $date = $date->modify('+1 day');

        // Indices 11-19: 9 sesiones mas con true range constante de 2
        // (respecto al cierre anterior de 130, ya estabilizado tras el gap).
        for ($i = 0; $i < 9; $i++) {
            $quotes[] = new HistoricalQuote($date, 130.0, 131.0, 129.0, 130.0, 1_000_000);
            $date = $date->modify('+1 day');
        }

        return $quotes;
    }
}
